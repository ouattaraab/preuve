<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\OpsReporter;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Destinataire des rapports d'exploitation (ST-0904).
 *
 * Réglable en exploitation, comme la passerelle SMS et le lecteur de pièces
 * d'identité : la personne qui reçoit ces rapports change d'équipe, part en
 * congés ou quitte l'entreprise — autant d'événements qui ne doivent pas
 * demander une livraison. Une adresse périmée est un rapport qui n'arrive plus,
 * et un contrôle qui cesse d'exister sans que rien ne le dise.
 *
 * L'ADRESSE N'EST PAS UN SECRET, contrairement à une clé d'API : elle est donc
 * renvoyée en clair. La masquer empêcherait de vérifier qu'elle est la bonne,
 * ce qui est exactement ce qu'un administrateur vient faire ici.
 *
 * Le changement est journalisé dans la chaîne d'audit : détourner les rapports
 * d'exploitation vers une autre adresse est une manière discrète de faire
 * cesser une surveillance.
 */
final class OpsRecipientController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OpsReporter $rapports,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        $adresse = $this->rapports->recipient();

        return response()->json([
            'recipient' => $adresse,
            'configured' => $adresse !== null,
            'reports' => [
                // Ce que le destinataire recevra, pour qu'il sache à quoi
                // s'attendre — et à quoi s'étonner de ne rien recevoir.
                'Réconciliation du bucket et de la base — le 1er du mois',
            ],
            'note' => 'Le rapport part même quand tout va bien : un message qui n\'arriverait qu\'en cas '.
                'd\'anomalie serait indistinguable d\'une tâche qui a cessé de tourner.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'recipient' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        // C'est la PRÉSENCE du champ qui distingue « effacer » de « ne pas
        // toucher » : ConvertEmptyStringsToNull rendrait sinon les deux
        // indiscernables.
        if (! $request->has('recipient')) {
            return response()->json(['message' => 'Aucun changement demandé.']);
        }

        $adresse = $request->string('recipient')->toString();
        $efface = $adresse === '';

        if ($efface) {
            $this->settings->forget(OpsReporter::RECIPIENT_SETTING);
        } else {
            $this->settings->set(OpsReporter::RECIPIENT_SETTING, $adresse, $administrateurId);
        }

        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            $efface ? 'admin.ops_recipient_cleared' : 'admin.ops_recipient_changed',
            'app_setting',
            0,
            // Jamais l'adresse elle-même : la chaîne d'audit est inaltérable et
            // exportable, et une donnée personnelle y entrerait pour toujours.
            ['configured' => ! $efface],
        );

        return response()->json([
            'message' => $efface
                ? 'Destinataire effacé. Les rapports d\'exploitation ne seront plus expédiés : '.
                  'ils restent dans les journaux du serveur.'
                : 'Destinataire enregistré.',
        ]);
    }
}
