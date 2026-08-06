<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Transferts de propriété (ST-0601 à ST-0603).
 *
 * Le vendeur initie vers un NUMÉRO ; l'acheteur confirme avec son code, ce qui
 * rattache son compte. Les deux confirmations déclenchent la finalisation.
 */
final class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    /**
     * Les transferts qui me concernent, dans les deux sens.
     *
     * SANS ELLE, LA CONFIRMATION DE L'ACHETEUR EST INATTEIGNABLE. L'invitation
     * qu'il reçoit est un code par SMS — délibérément, pour ne pas payer deux
     * messages — et ce code ne porte aucun numéro de transfert. Il n'avait donc
     * aucun moyen d'appeler `/transfers/{id}/confirm`, et le parcours de
     * transfert s'arrêtait là pour tout client.
     *
     * ENTRANTS RECONNUS PAR LE NUMÉRO, et non par un compte : au moment où le
     * vendeur engage le transfert, l'acheteur peut n'avoir jamais ouvert de
     * compte. C'est sa première connexion qui le fait apparaître ici, sans
     * qu'aucune étape de rattachement lui soit demandée.
     *
     * ELLE NE DIT RIEN DES PERSONNES. Un transfert sortant ne rend pas le
     * numéro de l'acheteur, un transfert entrant ne rend pas celui du vendeur
     * (règle métier absolue n° 4) : chacun sait avec qui il traite parce qu'il
     * l'a en face de lui, et la plateforme n'a pas à le lui confirmer — ni à
     * révéler qu'un numéro correspond à un compte.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $this->utilisateur($request);

        $transferts = Transfer::query()
            ->where(function ($requete) use ($utilisateur): void {
                // ON N'INTERROGE QUE LES COORDONNÉES QUI EXISTENT. Un compte
                // ouvert par adresse n'a pas de numéro : comparer `to_phone` à
                // NULL ne rendrait jamais vrai, mais l'écrire explicitement
                // évite de croire que la ligne fait quelque chose.
                $requete->where('from_user_id', $utilisateur->id)
                    ->orWhere('to_user_id', $utilisateur->id);

                if ($utilisateur->phone !== null && $utilisateur->phone !== '') {
                    $requete->orWhere('to_phone', $utilisateur->phone);
                }

                if ($utilisateur->email !== null && $utilisateur->email !== '') {
                    $requete->orWhere('to_email', $utilisateur->email);
                }
            })
            // Seuls ceux sur lesquels il reste un geste à faire. Les finalisés,
            // annulés et expirés appartiennent à l'historique du bien, pas à
            // une liste d'actions en attente.
            ->whereIn('status', [TransferStatus::Initiated->value, TransferStatus::BuyerConfirmed->value])
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->limit(50)
            ->get();

        $biens = Asset::query()
            ->whereIn('id', $transferts->pluck('asset_id')->all())
            ->get()
            ->keyBy('id');

        return response()->json([
            'transfers' => $transferts->map(function (Transfer $transfert) use ($utilisateur, $biens): array {
                $bien = $biens->get($transfert->asset_id);

                return $this->present($transfert) + [
                    // LE RÔLE EST CALCULÉ ICI, jamais deviné par le client :
                    // c'est lui qui décide si l'écran propose « confirmer la
                    // vente » ou « accepter ce bien », et une erreur de camp
                    // ferait annuler un transfert qu'on croyait accepter.
                    'role' => $transfert->from_user_id === $utilisateur->id ? 'seller' : 'buyer',
                    // LA VUE PUBLIQUE, MÊME POUR LE VENDEUR — donc sans le
                    // numéro complet du bien. Un transfert part vers un NUMÉRO
                    // saisi à la main : un chiffre de travers et la fiche
                    // complète d'un véhicule arriverait chez un inconnu, qui
                    // n'aurait plus qu'à la réclamer. La référence publique et
                    // la catégorie suffisent à reconnaître l'offre, et
                    // l'acheteur peut vérifier le numéro lui-même — sans
                    // compte, comme n'importe qui.
                    'asset' => $bien instanceof Asset ? new PublicAssetResource($bien) : null,
                ];
            })->all(),
        ]);
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        $request->validate([
            'buyer_phone' => ['required', 'string', 'max:30'],
            // L'ADRESSE PRÉVIENT, ELLE NE DÉSIGNE PAS. Le numéro reste ce sur
            // quoi porte le contrôle au moment d'accepter ; l'adresse est le
            // seul canal par lequel l'acheteur apprendra qu'on lui cède
            // quelque chose, tant qu'aucune passerelle SMS n'est branchée.
            'buyer_email' => ['sometimes', 'nullable', 'email', 'max:150'],
        ]);

        $bien = Asset::query()->whereKey($asset)->first();

        if (! $bien instanceof Asset) {
            abort(404);
        }

        try {
            $transfert = $this->transfers->initiate(
                $bien,
                $this->utilisateur($request),
                $request->string('buyer_phone')->toString(),
                $request->string('buyer_email')->toString() ?: null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['buyer_phone' => $e->getMessage()]);
        }

        return response()->json([
            // LE MESSAGE DIT LA VÉRITÉ DU CANAL. Promettre un envoi « à
            // l'acheteur » sans passerelle SMS et sans adresse ferait attendre
            // un vendeur pour un avertissement qui ne partira jamais.
            'message' => $transfert->to_email === null
                ? 'Transfert ouvert. Préviens l\'acheteur toi-même : sans son adresse '
                    .'e-mail, nous n\'avons aucun moyen de le joindre. Il expire dans 7 jours.'
                : 'L\'acheteur vient d\'être prévenu par e-mail. Le transfert expire dans 7 jours.',
            'transfer' => $this->present($transfert),
        ], 201);
    }

    public function confirm(Request $request, int $transfer): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'role' => ['required', 'in:seller,buyer'],
        ]);

        $transfert = Transfer::query()->whereKey($transfer)->first();

        if (! $transfert instanceof Transfer) {
            abort(404);
        }

        $utilisateur = $this->utilisateur($request);
        $code = $request->string('code')->toString();

        try {
            $transfert = $request->string('role')->toString() === 'seller'
                ? $this->transfers->confirmBySeller($transfert, $utilisateur, $code)
                : $this->transfers->confirmByBuyer($transfert, $utilisateur, $code);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $nouveau = $transfert->created_asset_id === null
            ? null
            : Asset::query()->whereKey($transfert->created_asset_id)->first();

        return response()->json([
            'transfer' => $this->present($transfert),
            'asset' => $nouveau instanceof Asset ? new PublicAssetResource($nouveau) : null,
        ]);
    }

    public function destroy(Request $request, int $transfer): JsonResponse
    {
        $transfert = Transfer::query()->whereKey($transfer)->first();

        if (! $transfert instanceof Transfer) {
            abort(404);
        }

        try {
            $transfert = $this->transfers->cancel($transfert, $this->utilisateur($request));
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }

        return response()->json(['transfer' => $this->present($transfert)]);
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }

    /** @return array<string, mixed> */
    private function present(Transfer $transfert): array
    {
        return [
            'id' => $transfert->id,
            'status' => $transfert->status->value,
            'status_label' => $transfert->status->label(),
            'seller_confirmed' => $transfert->seller_otp_at !== null,
            'buyer_confirmed' => $transfert->buyer_otp_at !== null,
            'expires_at' => $transfert->expires_at->toIso8601String(),
            'completed_at' => $transfert->completed_at?->toIso8601String(),
        ];
    }
}
