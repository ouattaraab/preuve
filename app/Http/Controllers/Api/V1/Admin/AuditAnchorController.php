<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\AuditAnchor;
use App\Models\User;
use App\Services\Audit\MailAnchorChannel;
use App\Services\Audit\StorageAnchorChannel;
use App\Services\AuditAnchorService;
use App\Services\AuditChain;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pilotage de l'ancrage de la chaîne d'audit.
 *
 * Expose surtout un état : depuis quand la chaîne est opposable, et si elle
 * l'est encore. C'est l'information dont un exploitant a besoin avant
 * d'affirmer quoi que ce soit devant un tiers — et celle qu'aucun tableau de
 * bord ne montre spontanément.
 */
final class AuditAnchorController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditAnchorService $anchors,
        private readonly AuditChain $chain,
    ) {}

    public function show(): JsonResponse
    {
        $dernier = $this->anchors->lastSuccessful();
        $destinataire = $this->settings->get(MailAnchorChannel::RECIPIENT_SETTING);
        $disque = $this->settings->get(StorageAnchorChannel::DISK_SETTING);

        return response()->json([
            'configuration' => [
                'mail_recipient' => is_string($destinataire) ? $destinataire : null,
                'storage_disk' => is_string($disque) ? $disque : null,
            ],
            'last_anchor' => $dernier === null ? null : [
                'id' => $dernier->id,
                'anchored_at' => $dernier->created_at->toIso8601String(),
                'head_id' => $dernier->head_id,
                'entry_count' => $dernier->entry_count,
                'channels' => $dernier->channels,
            ],
            'failed_since_last_success' => AuditAnchor::where('status', 'failed')
                ->when($dernier !== null, fn ($query) => $query->where('id', '>', $dernier?->id))
                ->count(),
            // L'avertissement est explicite : sans ancrage, la cohérence
            // interne ne prouve rien, l'algorithme étant public et
            // reproductible par quiconque peut écrire en base.
            'opposable' => $dernier !== null,
            'warning' => $dernier === null
                ? "Aucun ancrage externe : la chaîne d'audit n'est pas opposable. Configurez une adresse ".
                    "d'archivage ou un disque séparé."
                : null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'mail_recipient' => ['sometimes', 'nullable', 'email', 'max:150'],
            'storage_disk' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        $this->apply($request, 'mail_recipient', MailAnchorChannel::RECIPIENT_SETTING, $administrateurId);
        $this->apply($request, 'storage_disk', StorageAnchorChannel::DISK_SETTING, $administrateurId);

        // Modifier la destination des ancrages revient à choisir qui pourra
        // contredire la plateforme : l'action est sensible.
        $this->chain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.audit_anchor_configured',
            'app_setting',
            0,
            ['fields' => array_values(array_filter([
                $request->has('mail_recipient') ? 'mail_recipient' : null,
                $request->has('storage_disk') ? 'storage_disk' : null,
            ]))],
        );

        return response()->json(['message' => "Configuration de l'ancrage enregistrée."]);
    }

    /** Vérification à la demande, sans attendre le passage quotidien. */
    public function verify(): JsonResponse
    {
        $interne = $this->chain->verify();
        $externe = $this->anchors->verifyAgainstAnchors();

        return response()->json([
            'internal' => $interne,
            'anchors' => $externe,
            'valid' => $interne['valid'] && $externe['valid'],
        ]);
    }

    private function apply(Request $request, string $champ, string $cle, ?int $administrateurId): void
    {
        // La PRÉSENCE de la clé distingue « conserver » d'« effacer » : le
        // middleware ConvertEmptyStringsToNull rendrait sinon les deux cas
        // indistinguables.
        if (! $request->has($champ)) {
            return;
        }

        $valeur = $request->string($champ)->toString();

        if ($valeur === '') {
            $this->settings->forget($cle);

            return;
        }

        $this->settings->set($cle, $valeur, $administrateurId);
    }
}
