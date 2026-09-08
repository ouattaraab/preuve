<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\CompanyRole;
use App\Enums\NotificationType;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use DomainException;

/**
 * Délégation d'accès à une flotte (ST-0705).
 *
 * LE REPRÉSENTANT LÉGAL N'EST PAS UN MEMBRE, il est la société. Son accès ne
 * dépend d'aucune ligne de cette table et ne peut lui être retiré : un
 * administrateur de flotte révoquant le gérant priverait la société de son
 * propre parc.
 *
 * LA DÉLÉGATION S'ARRÊTE AUX ACTES DE PROPRIÉTÉ. Un opérateur marque des
 * véhicules toute la journée ; il ne peut ni les céder, ni les déclarer hors
 * d'usage. Confondre « gérer la flotte » et « disposer des biens » ferait d'un
 * téléphone d'employé volé une perte de parc.
 *
 * L'INVITATION PORTE SUR UN NUMÉRO, pas sur un compte : un agent de comptoir
 * embauché lundi ne s'inscrira pas avant d'en avoir besoin. Le rattachement se
 * fait à sa première connexion, et c'est le même geste que le transfert de
 * propriété — inviter quelqu'un qui n'existe pas encore est la situation
 * normale, pas l'exception.
 */
final class CompanyMemberService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Invite ou réactive un collaborateur.
     *
     * @throws DomainException
     */
    public function invite(Company $societe, User $auteur, string $telephone, CompanyRole $role): CompanyMember
    {
        $this->assertMayManageMembers($societe, $auteur);

        $numero = $this->otp->normalizeDestination($telephone);

        if ($numero === $auteur->phone) {
            throw new DomainException('Vous disposez déjà de cet accès.');
        }

        $compte = User::where('phone', $numero)->first();

        $membre = CompanyMember::firstOrNew([
            'company_id' => $societe->id,
            'invited_phone' => $numero,
        ]);

        $membre->fill([
            'user_id' => $compte?->id,
            'role' => $role,
            'invited_by' => $auteur->id,
            'is_active' => true,
        ])->save();

        $this->auditChain->append(
            ActorType::User,
            $auteur->id,
            'company.member_invited',
            'company',
            $societe->id,
            // Le numéro invité n'entre pas dans un journal inaltérable : seul
            // compte le rôle accordé et par qui.
            ['role' => $role->value, 'member_id' => $membre->id],
        );

        if ($compte instanceof User) {
            $this->notifications->notify(
                $compte,
                NotificationType::System,
                'Accès à une flotte',
                'Vous avez reçu un accès « '.$role->label().' » à la flotte de '.$societe->legal_name.'.',
            );
        }

        return $membre;
    }

    /**
     * Révoque un accès. La ligne est désactivée plutôt que supprimée : savoir
     * qui a eu accès, et quand, fait partie de la traçabilité que la
     * délégation existe pour préserver.
     *
     * @throws DomainException
     */
    public function revoke(Company $societe, User $auteur, int $membreId): bool
    {
        $this->assertMayManageMembers($societe, $auteur);

        $membre = CompanyMember::query()
            ->whereKey($membreId)
            ->where('company_id', $societe->id)
            ->first();

        if (! $membre instanceof CompanyMember) {
            return false;
        }

        $this->auditChain->append(
            ActorType::User,
            $auteur->id,
            'company.member_revoked',
            'company',
            $societe->id,
            ['role' => $membre->role->value, 'member_id' => $membre->id],
        );

        $membre->fill(['is_active' => false])->save();

        return true;
    }

    /**
     * Rôle effectif d'un utilisateur sur une flotte, ou null s'il n'y a
     * aucun accès.
     */
    public function roleOf(Company $societe, User $utilisateur): ?CompanyRole
    {
        // Le représentant légal EST la société : son accès ne dépend d'aucune
        // ligne et ne peut lui être retiré.
        if ($societe->owner_user_id === $utilisateur->id) {
            return CompanyRole::Admin;
        }

        $membre = CompanyMember::query()
            ->where('company_id', $societe->id)
            ->where('is_active', true)
            ->where(function ($requete) use ($utilisateur): void {
                $requete->where('user_id', $utilisateur->id)
                    ->orWhere('invited_phone', $utilisateur->phone);
            })
            ->first();

        if (! $membre instanceof CompanyMember) {
            return null;
        }

        // Rattachement à la première connexion : l'invitation portait sur un
        // numéro, le compte existe désormais.
        if ($membre->user_id === null) {
            $membre->fill(['user_id' => $utilisateur->id])->save();
        }

        return $membre->role;
    }

    /** Vrai si l'utilisateur est le représentant légal — seul à disposer des biens. */
    public function isLegalRepresentative(Company $societe, User $utilisateur): bool
    {
        return $societe->owner_user_id === $utilisateur->id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(Company $societe): array
    {
        $membres = [];

        foreach (CompanyMember::where('company_id', $societe->id)->orderBy('id')->get() as $membre) {
            $membres[] = [
                'id' => $membre->id,
                'role' => $membre->role->value,
                'role_label' => $membre->role->label(),
                'active' => $membre->is_active,
                'has_account' => $membre->user_id !== null,
                // Numéro partiellement masqué : un administrateur de flotte
                // doit reconnaître qui il a invité, sans que la liste devienne
                // un carnet d'adresses exportable.
                'phone_hint' => $this->maskPhone($membre->invited_phone),
            ];
        }

        return $membres;
    }

    /** @throws DomainException */
    private function assertMayManageMembers(Company $societe, User $auteur): void
    {
        $role = $this->roleOf($societe, $auteur);

        if ($role === null || ! $role->managesMembers()) {
            throw new DomainException('Seul un administrateur de flotte peut gérer les accès.');
        }
    }

    private function maskPhone(string $numero): string
    {
        $longueur = mb_strlen($numero);

        if ($longueur <= 4) {
            return str_repeat('•', $longueur);
        }

        return str_repeat('•', $longueur - 4).mb_substr($numero, -4);
    }
}
