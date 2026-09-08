<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use App\Services\AuditChain;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Otp\SmsProviderRegistry;
use App\Services\OtpService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Choix et configuration de la passerelle d'envoi des SMS, depuis l'espace
 * administrateur.
 *
 * Le fournisseur ivoirien n'étant pas arbitré au cadrage, le brancher devait
 * rester une opération d'exploitation et non une livraison : un changement de
 * contrat ou de tarif se règle ici, sans redéploiement.
 *
 * Deux règles gouvernent les secrets. Ils ne sont jamais renvoyés au client —
 * réafficher une clé d'API dans un formulaire l'expose à quiconque voit
 * l'écran et la fait fuir dans les journaux du navigateur. Et puisqu'ils ne
 * sont pas réaffichés, un enregistrement qui ne les resoumet pas les conserve :
 * les effacer en silence casserait la passerelle au premier utilisateur
 * suivant.
 */
final class SmsProviderController extends Controller
{
    private const MASQUE = '••••••••';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SmsProviderRegistry $registry,
        private readonly ConfigurableOtpSender $sender,
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
    ) {}

    /** Fournisseur actif et catalogue servant à construire le formulaire. */
    public function show(): JsonResponse
    {
        $actif = $this->sender->activeProviderKey();

        return response()->json([
            'active' => [
                'provider' => $actif,
                'config' => $this->maskSecrets($actif, $this->sender->activeConfig()),
            ],
            'available' => $this->registry->catalog(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'provider' => ['required', 'string'],
            'config' => ['sometimes', 'array'],
        ]);

        $fournisseur = $request->string('provider')->toString();

        if (! $this->registry->exists($fournisseur)) {
            throw ValidationException::withMessages([
                'provider' => "Fournisseur SMS inconnu : « {$fournisseur} ».",
            ]);
        }

        $soumis = $this->submittedConfig($request, $fournisseur);
        $secrets = $this->registry->secretFieldsFor($fournisseur);

        // Les champs non secrets forment la configuration en clair ; chaque
        // secret vit sous sa propre clé, chiffrée au repos.
        $ordinaires = array_diff_key($soumis, array_flip($secrets));

        $this->assertConfigurationIsUsable($fournisseur, $soumis, $ordinaires, $secrets);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        $this->settings->set(ConfigurableOtpSender::PROVIDER_KEY, $fournisseur, $administrateurId);
        $this->settings->set(ConfigurableOtpSender::CONFIG_KEY, $ordinaires, $administrateurId);

        foreach ($secrets as $champ) {
            $this->persistSecret($request, $champ, $administrateurId);
        }

        // Rerouter les SMS de la plateforme est une action sensible : elle
        // détournerait tous les codes d'accès vers une passerelle tierce.
        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.sms_provider_changed',
            'app_setting',
            0,
            [
                'provider' => $fournisseur,
                // Les noms des champs renseignés, jamais leurs valeurs.
                'configured_fields' => array_keys($soumis),
            ],
        );

        return response()->json([
            'message' => 'Fournisseur SMS enregistré.',
            'active' => [
                'provider' => $fournisseur,
                'config' => $this->maskSecrets($fournisseur, $this->sender->fresh()->activeConfig()),
            ],
        ]);
    }

    /**
     * Envoi d'essai. Sans lui, une mauvaise configuration ne se découvrirait
     * qu'au moment où un utilisateur réel n'a pas reçu son code.
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $destination = $this->otp->normalizeDestination($request->string('phone')->toString());

        try {
            $this->sender->fresh()->send($destination, $this->drillCode(), OtpPurpose::SensitiveAction);
        } catch (Throwable $e) {
            return response()->json([
                'delivered' => false,
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'delivered' => true,
            'message' => 'Message d\'essai remis à la passerelle.',
        ]);
    }

    /**
     * Configuration soumise, restreinte aux champs déclarés par le
     * fournisseur : la table de réglages n'a pas à recevoir de clés inventées.
     *
     * @return array<string, string>
     */
    private function submittedConfig(Request $request, string $fournisseur): array
    {
        $config = [];

        foreach (array_keys($this->registry->fieldsFor($fournisseur)) as $champ) {
            $valeur = $request->input('config.'.$champ);

            if (is_string($valeur) && trim($valeur) !== '') {
                $config[$champ] = trim($valeur);
            }
        }

        return $config;
    }

    /**
     * @param  array<string, string>  $soumis
     * @param  array<string, string>  $ordinaires
     * @param  list<string>  $secrets
     */
    private function assertConfigurationIsUsable(
        string $fournisseur,
        array $soumis,
        array $ordinaires,
        array $secrets,
    ): void {
        // Un secret déjà enregistré compte comme présent : l'interface ne le
        // réaffiche pas, l'administrateur ne peut donc pas le resoumettre.
        $connus = $soumis;

        foreach ($secrets as $champ) {
            if ($this->settings->has(ConfigurableOtpSender::CONFIG_KEY.'.'.$champ)) {
                $connus[$champ] = self::MASQUE;
            }
        }

        $manquants = $this->registry->missingRequiredFields($fournisseur, $connus);

        if ($manquants !== []) {
            throw ValidationException::withMessages(
                array_combine(
                    array_map(fn (string $champ): string => 'config.'.$champ, $manquants),
                    array_map(
                        fn (string $champ): string => "Le champ « {$champ} » est obligatoire pour ce fournisseur.",
                        $manquants,
                    ),
                )
            );
        }

        $url = $ordinaires['endpoint_url'] ?? null;

        if (is_string($url) && ! str_starts_with($url, 'https://') && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'config.endpoint_url' => "L'adresse de la passerelle doit être en HTTPS : le code transiterait ".
                    'sinon en clair sur le réseau.',
            ]);
        }

        // En test comme en production, une adresse manifestement non chiffrée
        // est refusée à la saisie : le contrôle d'exécution de HttpOtpSender
        // tolère http:// en développement, mais rien ne justifie de
        // l'enregistrer volontairement.
        if (is_string($url) && str_starts_with($url, 'http://')) {
            throw ValidationException::withMessages([
                'config.endpoint_url' => "L'adresse de la passerelle doit être en HTTPS : le code transiterait ".
                    'sinon en clair sur le réseau.',
            ]);
        }

        // ANTI-SSRF : la passerelle reçoit un secret d'authentification à chaque
        // envoi (voir HttpSmsGateway). La viser vers le réseau interne
        // — métadonnées cloud, service back-end — exfiltrerait ce secret et
        // ouvrirait un pivot. On refuse à la saisie une URL qui pointe vers une
        // IP interne ou réservée.
        if (is_string($url) && $url !== '') {
            $verif = Validator::make(['url' => $url], ['url' => [new SafeExternalUrl]]);

            if ($verif->fails()) {
                throw ValidationException::withMessages([
                    'config.endpoint_url' => $verif->errors()->get('url'),
                ]);
            }
        }
    }

    private function persistSecret(Request $request, string $champ, ?int $administrateurId): void
    {
        $cle = ConfigurableOtpSender::CONFIG_KEY.'.'.$champ;

        // C'est la PRÉSENCE de la clé qui distingue « conserver » de
        // « effacer », jamais sa valeur : le middleware
        // ConvertEmptyStringsToNull, actif par défaut, transforme une chaîne
        // vide en null, ce qui rendrait un effacement explicite
        // indistinguable d'un champ jamais soumis.
        if (! $request->has('config.'.$champ)) {
            // Champ absent : le secret déjà enregistré est conservé, puisque
            // l'interface ne l'a pas réaffiché.
            return;
        }

        $valeur = $request->input('config.'.$champ);

        if (! is_string($valeur) || trim($valeur) === '') {
            // Champ soumis vide : effacement demandé.
            $this->settings->forget($cle);

            return;
        }

        $this->settings->setSecret($cle, trim($valeur), $administrateurId);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function maskSecrets(string $fournisseur, array $config): array
    {
        foreach ($this->registry->secretFieldsFor($fournisseur) as $champ) {
            if (isset($config[$champ])) {
                $config[$champ] = self::MASQUE;
            }
        }

        return $config;
    }

    /**
     * Code de l'envoi d'essai : jamais un vrai code, et sans valeur pour
     * l'authentification — il n'est enregistré nulle part.
     */
    private function drillCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
