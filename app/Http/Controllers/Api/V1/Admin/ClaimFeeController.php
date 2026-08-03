<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\ClaimArbitrationService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Montant des frais de dossier de réclamation (ST-0501).
 *
 * RÉGLABLE EN EXPLOITATION, ET C'EST LE POINT. Les frais conditionnent le dépôt
 * depuis le 03/08/2026 : ils filtrent les dossiers de nuisance, mais ils
 * filtrent aussi, mécaniquement, ceux qui n'ont pas la somme. Pouvoir les
 * lever d'un réglage est ce qui empêche ce filtre de devenir permanent —
 * campagne de lancement, région sinistrée, période de gratuité décidée le
 * matin pour l'après-midi.
 *
 * ZÉRO EST UNE VALEUR LÉGITIME, pas une absence de réglage. À zéro, plus rien
 * ne bloque : le recours redevient ouvert à tous.
 *
 * CHAQUE CHANGEMENT EST JOURNALISÉ, avec l'ancien et le nouveau montant.
 * Relever les frais restreint l'accès au seul recours d'une victime dont le
 * bien a été enregistré par un tiers : cela ne doit pas pouvoir se faire sans
 * trace, ni se découvrir après coup.
 */
final class ClaimFeeController extends Controller
{
    /**
     * Plafond de saisie. Il ne protège pas d'une décision délibérée — un
     * administrateur peut légitimement relever les frais — mais d'un zéro de
     * trop, qui fermerait le recours à tout le monde sans que personne ne l'ait
     * voulu.
     */
    private const MAXIMUM_FCFA = 50_000;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ClaimArbitrationService $arbitrage,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        $montant = $this->arbitrage->feeAmount();

        return response()->json([
            'amount_fcfa' => $montant,
            'blocking' => $montant > 0,
            'default_fcfa' => config('preuve.claim_fee_fcfa'),
            'max_fcfa' => self::MAXIMUM_FCFA,
            'note' => $montant > 0
                ? 'Le dépôt d\'une réclamation exige ce règlement. Il est remboursé si la réclamation '.
                  'aboutit. Mettre le montant à 0 ouvre le recours sans condition.'
                : 'Les frais sont levés : le dépôt d\'une réclamation est ouvert à tous.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            // `min:0` et non `min:1` : zéro est la gratuité, pas une erreur.
            'amount_fcfa' => ['required', 'integer', 'min:0', 'max:'.self::MAXIMUM_FCFA],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        $ancien = $this->arbitrage->feeAmount();
        $nouveau = (int) $request->integer('amount_fcfa');

        $this->settings->set(ClaimArbitrationService::FEE_SETTING, $nouveau, $administrateurId);

        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.claim_fee_changed',
            'app_setting',
            0,
            // Les deux montants : savoir qu'un changement a eu lieu ne suffit
            // pas à juger s'il a restreint ou ouvert l'accès au recours.
            ['from_fcfa' => $ancien, 'to_fcfa' => $nouveau],
        );

        return response()->json([
            'message' => $nouveau === 0
                ? 'Frais levés : le dépôt d\'une réclamation est désormais ouvert sans règlement.'
                : sprintf('Frais de dossier fixés à %s FCFA.', number_format($nouveau, 0, ',', ' ')),
            'amount_fcfa' => $nouveau,
            'blocking' => $nouveau > 0,
        ]);
    }
}
