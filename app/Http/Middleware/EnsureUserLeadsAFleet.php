<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyMemberService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve l'espace loueur à qui appartient réellement à une société.
 *
 * UN ESPACE À PART, ET NON UN COIN DU BACK-OFFICE. Un loueur est un CLIENT :
 * lui ouvrir la console d'administration lui donnerait la piste d'audit, la
 * modération d'identité et le registre entier. Les deux espaces partagent le
 * gardien de session de Laravel, mais chacun a son propre contrôle — celui du
 * back-office refuse un loueur, celui-ci refuse un agent qui ne dirige aucune
 * flotte.
 *
 * LE CONTRÔLE EST REFAIT À CHAQUE REQUÊTE, jamais mis en session : une
 * exclusion de société doit fermer la porte au prochain clic, pas à la
 * prochaine reconnexion.
 */
final class EnsureUserLeadsAFleet
{
    public function __construct(private readonly CompanyMemberService $membres) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User || self::societesDe($this->membres, $utilisateur) === []) {
            return redirect()->route('fleet.login');
        }

        return $next($request);
    }

    /**
     * Les sociétés que ce compte peut piloter, dans l'ordre de leur nom.
     *
     * ADMINISTRATEUR SEULEMENT : importer un parc écrase des statuts en masse,
     * c'est un acte de direction. Un opérateur marque des véhicules en
     * location, il ne reprend pas l'inventaire.
     *
     * @return list<Company>
     */
    public static function societesDe(CompanyMemberService $membres, User $utilisateur): array
    {
        // EN UNE REQUÊTE, et non en filtrant toutes les sociétés en mémoire :
        // la table grandira avec le produit, et parcourir un annuaire entier
        // pour y trouver deux lignes se paie à chaque affichage de page.
        //
        // Deux chemins d'accès, et ils sont distincts : le représentant légal
        // EST la société — son accès ne dépend d'aucune ligne et ne peut lui
        // être retiré — tandis qu'un collaborateur tient le sien d'une
        // adhésion ACTIVE, qu'une exclusion referme.
        $societes = Company::query()
            ->where(function (Builder $requete) use ($utilisateur): void {
                $requete->where('owner_user_id', $utilisateur->id)
                    ->orWhereExists(function (QueryBuilder $membre) use ($utilisateur): void {
                        $membre->selectRaw('1')
                            ->from('company_members')
                            ->whereColumn('company_members.company_id', 'companies.id')
                            ->where('company_members.user_id', $utilisateur->id)
                            ->where('company_members.is_active', true)
                            ->where('company_members.role', 'admin');
                    });
            })
            ->orderBy('legal_name')
            ->get()
            // Ultime filtre par le service : lui seul fait autorité sur ce
            // qu'est un rôle, et une requête qui diverge un jour de sa règle
            // ouvrirait une porte que personne ne verrait.
            ->filter(fn (Company $societe): bool => $membres->roleOf($societe, $utilisateur)?->value === 'admin')
            ->values();

        // `values()` ré-indexe : le tableau EST une liste. L'analyse statique
        // ne sait pas le déduire d'une collection Eloquent, et on le lui dit
        // plutôt que d'affaiblir le type de retour, qui est ce que lit
        // l'appelant.
        /** @var list<Company> */
        return $societes->all();
    }
}
