<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActorType;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\OtpService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentification sans mot de passe (ST-0101 / ST-0102) : un code envoyé au
 * téléphone, échangé contre un jeton Sanctum.
 *
 * Les deux stories partagent un seul parcours : au premier code vérifié le
 * compte est créé, aux suivants il est retrouvé. Séparer « inscription » et
 * « connexion » obligerait l'utilisateur à savoir s'il a déjà un compte — et
 * exposerait, par la différence de parcours, l'existence de ce compte.
 */
final class OtpAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Émet un code. La réponse est délibérément identique que le numéro soit
     * connu ou non : toute différence observable ferait de cette route un
     * service d'énumération d'abonnés.
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
            // Requise à la création du compte tant que le canal est le
            // courriel : c'est elle qui recevra le code.
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
        ]);

        $telephone = $this->otp->normalizeDestination($request->string('phone')->toString());
        $motif = OtpPurpose::from($request->string('purpose')->toString());

        $livraison = $this->deliveryTarget($telephone, $request->string('email')->toString() ?: null);

        // Réponse INVARIABLE, y compris quand rien n'a été envoyé : toute
        // différence observable — message, délai, code HTTP — ferait de cette
        // route un service d'énumération d'abonnés.
        $reponse = [
            'message' => 'Si ce numéro est joignable, un code vient de lui être envoyé.',
            'expires_in' => $this->otp->ttlSeconds(),
        ];

        if ($livraison === null) {
            return response()->json($reponse);
        }

        $this->otp->request($telephone, $motif, $livraison['channel'], $livraison['address']);

        return response()->json($reponse);
    }

    /**
     * Adresse retenue à la création d'un compte par le canal courriel.
     *
     * C'est celle qui a reçu le code, donc la seule dont la maîtrise vient
     * d'être prouvée. Elle n'est acceptée que si aucun compte ne la porte
     * déjà : `users.email` est unique, et l'accepter deux fois échouerait en
     * base plutôt que d'être refusé proprement.
     */
    private function pendingEmail(Request $request): ?string
    {
        $adresse = $request->string('email')->toString();

        if ($adresse === '' || User::where('email', $adresse)->exists()) {
            return null;
        }

        return $adresse;
    }

    /**
     * Où envoyer le code, ou `null` s'il ne doit pas partir.
     *
     * RÈGLE DE SÉCURITÉ CENTRALE : pour un compte qui EXISTE, l'adresse de
     * livraison vient de la base, jamais de la requête. Accepter une adresse
     * soumise permettrait à quiconque connaît un numéro de faire envoyer le
     * code chez lui — c'est-à-dire de prendre n'importe quel compte, sans rien
     * savoir d'autre. C'est le point sur lequel un canal courriel se distingue
     * du SMS, où la maîtrise du numéro fait office de preuve.
     *
     * @return array{channel: OtpChannel, address: string|null}|null
     */
    private function deliveryTarget(string $telephone, ?string $emailSoumis): ?array
    {
        // Le canal actif est celui réglé dans l'espace administrateur.
        $fournisseur = $this->settings->get(ConfigurableOtpSender::PROVIDER_KEY);

        if ($fournisseur !== 'mail') {
            return ['channel' => OtpChannel::Sms, 'address' => null];
        }

        $compte = User::where('phone', $telephone)->first();

        if ($compte instanceof User) {
            // Compte existant : uniquement l'adresse au dossier. Un compte sans
            // adresse ne peut rien recevoir par ce canal — on ne le dit pas au
            // demandeur, qui apprendrait ainsi que le compte existe.
            $adresse = $compte->email;

            return is_string($adresse) && $adresse !== ''
                ? ['channel' => OtpChannel::Email, 'address' => $adresse]
                : null;
        }

        // Compte inexistant : c'est une création, et l'adresse soumise est la
        // seule dont on dispose. La vérifier prouvera qu'elle appartient bien
        // au demandeur.
        return $emailSoumis === null
            ? null
            : ['channel' => OtpChannel::Email, 'address' => $emailSoumis];
    }

    /**
     * Vérifie le code et ouvre une session. Le compte est créé au premier
     * passage : c'est la vérification du code qui prouve la maîtrise du
     * numéro, donc le seul moment où la création est légitime.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'purpose' => ['required', Rule::enum(OtpPurpose::class)],
            'code' => ['required', 'string'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            // Nom donné à l'inscription. FACULTATIF, ET IL LE RESTE : on
            // n'exige jamais une identité pour ouvrir un compte, seulement pour
            // céder un bien ou réclamer (CT-06). Il sert à s'adresser à
            // quelqu'un, pas à l'identifier — le KYC seul en atteste.
            'full_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'revoke_other_devices' => ['sometimes', 'boolean'],
        ]);

        $motif = OtpPurpose::from($request->string('purpose')->toString());

        $defi = $this->otp->verify(
            $request->string('phone')->toString(),
            $request->string('code')->toString(),
            $motif,
        );

        // CE QUI A ÉTÉ PROUVÉ DÉPEND DU CANAL. Un code reçu par SMS prouve la
        // maîtrise du numéro ; reçu par courriel, il ne prouve que celle de la
        // boîte. Marquer `phone_verified_at` dans les deux cas inscrirait une
        // vérification qui n'a pas eu lieu — sur la colonne même qui atteste
        // qu'elle a eu lieu.
        $parSms = $defi->channel === OtpChannel::Sms;

        $telephone = $this->otp->normalizeDestination($request->string('phone')->toString());
        $existant = User::where('phone', $telephone)->first();

        // La chaîne d'audit englobe la création du compte : sans transaction
        // commune, un compte pourrait exister sans trace d'audit, ou l'inverse.
        // UN COMPTE SUSPENDU NE SE RECONNECTE PAS. Sans ce contrôle, la
        // suspension ne serait qu'une étiquette : le titulaire redemanderait un
        // code et reprendrait la main. Le refus est volontairement indistinct
        // d'un refus de code — dire « vous êtes suspendu » à qui présente un
        // code valide confirmerait que le compte existe.
        if ($existant instanceof User && $existant->getAttribute('status') === 'suspended') {
            abort(403, 'Ce compte est suspendu.');
        }

        $adresseVerifiee = $parSms ? null : $this->pendingEmail($request);

        // À LA CRÉATION SEULEMENT, et jamais en écrasement : accepter un nom à
        // chaque connexion permettrait de renommer un compte à volonté depuis
        // n'importe quel appareil, sur la colonne que la revue KYC rapproche de
        // la pièce d'identité.
        $nom = $request->string('full_name')->trim()->toString();
        $nom = $nom === '' ? null : $nom;

        $utilisateur = $existant ?? $this->auditChain->transaction(
            function () use ($telephone, $parSms, $adresseVerifiee, $nom): array {
                $nouveau = User::create(array_filter([
                    'phone' => $telephone,
                    'email' => $adresseVerifiee,
                    'full_name' => $nom,
                ]));

                $nouveau->forceFill($parSms
                    ? ['phone_verified_at' => now()]
                    : ['email_verified_at' => now()])->save();

                return [
                    'result' => $nouveau,
                    'actorType' => ActorType::User,
                    'actorId' => $nouveau->id,
                    'action' => 'auth.account_created',
                    'entityType' => 'user',
                    'entityId' => $nouveau->id,
                    // Jamais le numéro : audit_log est inaltérable et
                    // survivrait à tout exercice du droit à l'effacement
                    // (Loi 2013-450, ST-0105).
                    'payload' => ['channel' => $parSms ? 'sms' : 'email'],
                ];
            }
        );

        // Symétrique, et pour la même raison : un code vérifié atteste de la
        // maîtrise du canal qui l'a porté. Un compte créé à la main par
        // l'exploitant n'a rien prouvé tant que personne ne s'y est connecté ;
        // c'est cette première connexion qui l'atteste, et elle doit s'inscrire.
        if ($existant !== null) {
            $colonne = $parSms ? 'phone_verified_at' : 'email_verified_at';

            if ($utilisateur->getAttribute($colonne) === null) {
                $utilisateur->forceFill([$colonne => now()])->save();
            }
        }

        if ($request->boolean('revoke_other_devices')) {
            $utilisateur->tokens()->delete();
        }

        $jeton = $utilisateur->createToken('mobile')->plainTextToken;

        $this->auditChain->append(
            ActorType::User,
            $utilisateur->id,
            'auth.session_opened',
            'user',
            $utilisateur->id,
            [
                'purpose' => $motif->value,
                'revoked_other_devices' => $request->boolean('revoke_other_devices'),
            ],
        );

        return response()->json([
            'token' => $jeton,
            'user' => [
                'id' => $utilisateur->id,
                'phone' => $utilisateur->phone,
                'full_name' => $utilisateur->full_name,
                'kyc_status' => $utilisateur->kyc_status,
            ],
        ]);
    }

    /** Profil du porteur du jeton. */
    public function me(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        return response()->json([
            'id' => $utilisateur?->getAuthIdentifier(),
            'phone' => $utilisateur instanceof User ? $utilisateur->phone : null,
            'full_name' => $utilisateur instanceof User ? $utilisateur->full_name : null,
            'kyc_status' => $utilisateur instanceof User ? $utilisateur->kyc_status : null,
            // LES SOCIÉTÉS DONT LE COMPTE EST MEMBRE. Sans elles, aucun client
            // ne peut atteindre la flotte : tous ses points d'entrée sont en
            // `/fleet/{company}/…`, et rien ne disait à l'application qu'un
            // compte est un loueur, ni de quelle société. Le tableau de bord
            // existait et n'était ouvrable par personne.
            //
            // LE RÔLE ACCOMPAGNE LA SOCIÉTÉ, et il décide de ce que l'écran
            // propose : un opérateur marque des véhicules en location, il
            // n'invite pas de collaborateurs. Le deviner côté client ferait
            // afficher des boutons que le serveur refuse.
            'companies' => $utilisateur instanceof User ? $this->societesDe($utilisateur) : [],
        ]);
    }

    /**
     * Sociétés dont ce compte est membre actif.
     *
     * @return list<array{id: int, name: string, role: string, role_label: string}>
     */
    private function societesDe(User $utilisateur): array
    {
        $societes = [];

        $membres = CompanyMember::query()
            ->where('user_id', $utilisateur->id)
            ->where('is_active', true)
            ->get();

        foreach ($membres as $membre) {
            $societe = Company::find($membre->company_id);

            if (! $societe instanceof Company) {
                continue;
            }

            $societes[] = [
                'id' => $societe->id,
                'name' => (string) $societe->legal_name,
                'role' => $membre->role->value,
                'role_label' => $membre->role->label(),
            ];
        }

        return $societes;
    }

    /** Ferme la seule session courante, sans toucher aux autres appareils. */
    public function logout(Request $request): JsonResponse
    {
        $jeton = $request->user()?->currentAccessToken();

        // Un jeton de session web (TransientToken) n'a rien à révoquer : seuls
        // les jetons personnels émis à un appareil sont persistés.
        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }

        return response()->json(['message' => 'Session fermée.']);
    }
}
