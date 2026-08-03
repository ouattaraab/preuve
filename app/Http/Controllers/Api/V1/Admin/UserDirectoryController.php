<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Annuaire des comptes, vu du back-office (ST-0902).
 *
 * LE CONTACT EST MASQUÉ, y compris pour un agent. Le numéro et l'adresse ne
 * sont pas nécessaires pour suspendre un compte ou constater son niveau de
 * vérification ; les afficher ferait de chaque poste de back-office un carnet
 * d'adresses exportable, et d'un compte agent compromis une fuite de la base
 * d'abonnés. L'accès complet relève de la réquisition judiciaire, hors de cet
 * écran.
 *
 * SUSPENDRE UN COMPTE NE SUSPEND PAS SES BIENS, et c'est la limite qui compte.
 * Un bien déclaré volé reste déclaré volé ; un acheteur qui vérifie ce châssis
 * reçoit le même verdict qu'avant. Couper la protection avec le compte
 * punirait les biens plutôt que le titulaire — et le premier lésé serait
 * l'acheteur de bonne foi, qui n'a rien fait.
 */
final class UserDirectoryController extends Controller
{
    private const PAR_PAGE = 25;

    public function __construct(private readonly AuditChain $auditChain) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'kyc' => ['sometimes', 'nullable', 'string', 'max:12'],
            'status' => ['sometimes', 'nullable', 'string', 'max:12'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $requete = User::query();

        // La recherche porte sur le NOM seulement. Chercher par numéro
        // permettrait de vérifier qu'un numéro donné est inscrit — c'est-à-dire
        // exactement ce que le masquage empêche.
        $recherche = $request->string('q')->toString();

        if ($recherche !== '') {
            $requete->where('full_name', 'like', '%'.$recherche.'%');
        }

        foreach (['kyc' => 'kyc_status', 'status' => 'status'] as $parametre => $colonne) {
            $valeur = $request->string($parametre)->toString();

            if ($valeur !== '') {
                $requete->where($colonne, $valeur);
            }
        }

        $page = $requete->orderByDesc('id')
            ->paginate(self::PAR_PAGE, ['*'], 'page', (int) $request->integer('page', 1));

        $identifiants = [];

        foreach ($page->getCollection() as $compte) {
            $identifiants[] = $compte->id;
        }

        $biens = $this->biensParDetenteur($identifiants);

        return response()->json([
            'users' => $page->getCollection()->map(
                fn (User $compte): array => $this->presenter($compte, $biens[$compte->id] ?? 0)
            )->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'notice' => 'Les coordonnées sont masquées. L\'accès complet relève de la réquisition '.
                'judiciaire, et se fait hors de cet écran.',
        ]);
    }

    /** Suspend ou rétablit un compte. */
    public function setStatus(Request $request, int $user): JsonResponse
    {
        $request->validate([
            'suspended' => ['required', 'boolean'],
            // Un motif écrit : une suspension sans raison est incontestable,
            // donc arbitraire — et le titulaire ne saurait pas quoi corriger.
            'reason' => ['required_if:suspended,true', 'nullable', 'string', 'max:280'],
        ]);

        $compte = User::find($user);

        if (! $compte instanceof User) {
            abort(404);
        }

        $administrateur = $request->user();
        $suspendre = $request->boolean('suspended');

        // Un administrateur ne se suspend pas lui-même : il se fermerait la
        // porte, et personne d'autre ne pourrait la rouvrir sur une
        // installation qui n'a qu'un compte.
        if ($administrateur instanceof User && $administrateur->id === $compte->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas suspendre votre propre compte.',
            ], 422);
        }

        $this->auditChain->append(
            ActorType::Agent,
            $administrateur instanceof User ? $administrateur->id : null,
            $suspendre ? 'admin.user_suspended' : 'admin.user_restored',
            'user',
            $compte->id,
            // Le motif entre dans la chaîne : c'est lui qui rend la décision
            // contestable. Jamais le numéro ni l'adresse — la chaîne est
            // inaltérable et survivrait à tout effacement demandé.
            ['reason' => $suspendre ? $request->string('reason')->toString() : null],
        );

        $compte->forceFill(['status' => $suspendre ? 'suspended' : 'active'])->save();

        // Les jetons sont révoqués sur-le-champ : sans cela, la suspension ne
        // prendrait effet qu'à l'expiration de la session en cours, c'est-à-dire
        // trop tard.
        if ($suspendre) {
            $compte->tokens()->delete();
        }

        return response()->json([
            'message' => $suspendre
                ? 'Compte suspendu. Ses biens restent protégés et consultables : un bien déclaré volé '.
                  'le reste.'
                : 'Compte rétabli.',
            'user' => $this->presenter($compte->fresh() ?? $compte, $this->biensDe($compte)),
        ]);
    }

    /**
     * @param  list<int>  $identifiants
     * @return array<int, int>
     */
    private function biensParDetenteur(array $identifiants): array
    {
        if ($identifiants === []) {
            return [];
        }

        $lignes = DB::table('assets')
            ->select('owner_user_id', DB::raw('COUNT(*) as total'))
            ->whereIn('owner_user_id', $identifiants)
            ->whereNotNull('active_flag')
            ->groupBy('owner_user_id')
            ->get();

        $compte = [];

        foreach ($lignes as $ligne) {
            $cle = $ligne->owner_user_id ?? null;
            $total = $ligne->total ?? 0;

            if (is_numeric($cle) && is_numeric($total)) {
                $compte[(int) $cle] = (int) $total;
            }
        }

        return $compte;
    }

    private function biensDe(User $compte): int
    {
        return Asset::where('owner_user_id', $compte->id)->whereNotNull('active_flag')->count();
    }

    /** @return array<string, mixed> */
    private function presenter(User $compte, int $biens): array
    {
        return [
            'id' => $compte->id,
            'name' => $compte->full_name,
            // Masqué : assez pour distinguer deux lignes, pas assez pour
            // joindre quelqu'un ni pour vérifier qu'un numéro connu est inscrit.
            'contact' => $this->masquer($compte),
            'kyc_status' => $compte->kycStatus()->value,
            'kyc_status_label' => $compte->kycStatus()->label(),
            'role' => $compte->role->value,
            'assets' => $biens,
            'status' => $compte->getAttribute('status'),
            'joined_at' => $compte->created_at?->toIso8601String(),
        ];
    }

    /** Deux derniers caractères seulement : reconnaître, pas joindre. */
    private function masquer(User $compte): string
    {
        $numero = $compte->phone;
        $longueur = mb_strlen($numero);

        return $longueur <= 2
            ? str_repeat('•', $longueur)
            : str_repeat('•', $longueur - 2).mb_substr($numero, -2);
    }
}
