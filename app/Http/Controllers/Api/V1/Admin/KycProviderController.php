<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use App\Services\AuditChain;
use App\Services\Kyc\MindeeIdentityReader;
use App\Services\Scan\MindeeDocumentReader;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuration du fournisseur d'extraction des pièces d'identité.
 *
 * Même logique que la passerelle SMS : une clé d'API expire, se révoque et
 * change de plan tarifaire — autant d'événements d'exploitation qui ne doivent
 * pas demander une livraison. La clé est chiffrée au repos et jamais renvoyée
 * au client ; un enregistrement qui ne la resoumet pas la conserve, puisque
 * l'interface ne l'a pas réaffichée.
 *
 * Sans clé configurée, l'extraction n'a pas lieu et tous les dossiers partent
 * en revue manuelle. C'est un mode dégradé assumé, pas une panne : un agent
 * tranche de toute façon en dernier ressort.
 */
final class KycProviderController extends Controller
{
    private const MASQUE = '••••••••';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        $cle = $this->settings->get(MindeeIdentityReader::API_KEY_SETTING);
        $adresse = $this->settings->get(MindeeIdentityReader::ENDPOINT_SETTING);

        return response()->json([
            'provider' => 'mindee',
            'configured' => is_string($cle) && $cle !== '',
            'api_key' => is_string($cle) && $cle !== '' ? self::MASQUE : null,
            'endpoint' => is_string($adresse) ? $adresse : null,
            // Même clé, autre produit : le scan de carte grise (ST-0202) passe
            // par le même compte. Séparer les clés laisserait la moitié des
            // extractions tomber le jour d'une rotation, sans rien signaler.
            'scan_endpoint' => is_string($scan = $this->settings->get(MindeeDocumentReader::ENDPOINT_SETTING))
                ? $scan
                : null,
            'fallback' => 'Sans clé, l\'extraction automatique n\'a pas lieu et chaque dossier est examiné '.
                'manuellement par un agent. La vérification d\'identité reste possible.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255', 'url', new SafeExternalUrl],
            'scan_endpoint' => ['sometimes', 'nullable', 'string', 'max:255', 'url', new SafeExternalUrl],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        // C'est la PRÉSENCE de la clé qui distingue « conserver » d'« effacer » :
        // le middleware ConvertEmptyStringsToNull transforme une chaîne vide en
        // null, ce qui rendrait sinon un effacement explicite indistinguable
        // d'un champ jamais soumis.
        if ($request->has('api_key')) {
            $valeur = $request->string('api_key')->toString();

            if ($valeur === '') {
                $this->settings->forget(MindeeIdentityReader::API_KEY_SETTING);
            } else {
                $this->settings->setSecret(MindeeIdentityReader::API_KEY_SETTING, $valeur, $administrateurId);
            }
        }

        if ($request->has('endpoint')) {
            $adresse = $request->string('endpoint')->toString();

            if ($adresse === '') {
                $this->settings->forget(MindeeIdentityReader::ENDPOINT_SETTING);
            } else {
                $this->settings->set(MindeeIdentityReader::ENDPOINT_SETTING, $adresse, $administrateurId);
            }
        }

        if ($request->has('scan_endpoint')) {
            $adresse = $request->string('scan_endpoint')->toString();

            if ($adresse === '') {
                $this->settings->forget(MindeeDocumentReader::ENDPOINT_SETTING);
            } else {
                $this->settings->set(MindeeDocumentReader::ENDPOINT_SETTING, $adresse, $administrateurId);
            }
        }

        // Changer le lecteur de pièces d'identité est une action sensible : il
        // voit passer chaque CNI déposée sur la plateforme.
        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.kyc_provider_changed',
            'app_setting',
            0,
            // Jamais la clé, seulement ce qui a été touché.
            ['fields' => array_values(array_filter([
                $request->has('api_key') ? 'api_key' : null,
                $request->has('endpoint') ? 'endpoint' : null,
                $request->has('scan_endpoint') ? 'scan_endpoint' : null,
            ]))],
        );

        return response()->json(['message' => 'Configuration du fournisseur KYC enregistrée.']);
    }
}
