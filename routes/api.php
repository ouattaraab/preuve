<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AppStatsController;
use App\Http\Controllers\Api\V1\Admin\AssetRegistryController;
use App\Http\Controllers\Api\V1\Admin\AuditAnchorController;
use App\Http\Controllers\Api\V1\Admin\AuditTrailController;
use App\Http\Controllers\Api\V1\Admin\CaptchaProviderController;
use App\Http\Controllers\Api\V1\Admin\CategoryAdminController;
use App\Http\Controllers\Api\V1\Admin\ClaimFeeController;
use App\Http\Controllers\Api\V1\Admin\ClaimReviewController;
use App\Http\Controllers\Api\V1\Admin\CompanyValidationController;
use App\Http\Controllers\Api\V1\Admin\DocumentReviewController;
use App\Http\Controllers\Api\V1\Admin\IdentityDisclosureController;
use App\Http\Controllers\Api\V1\Admin\KycProviderController;
use App\Http\Controllers\Api\V1\Admin\KycReviewController;
use App\Http\Controllers\Api\V1\Admin\LegalContactController;
use App\Http\Controllers\Api\V1\Admin\ObservabilityController;
use App\Http\Controllers\Api\V1\Admin\OpsRecipientController;
use App\Http\Controllers\Api\V1\Admin\OverviewController;
use App\Http\Controllers\Api\V1\Admin\PlatformStateController;
use App\Http\Controllers\Api\V1\Admin\PricingController;
use App\Http\Controllers\Api\V1\Admin\PushProviderController;
use App\Http\Controllers\Api\V1\Admin\SmsProviderController;
use App\Http\Controllers\Api\V1\Admin\TeamController;
use App\Http\Controllers\Api\V1\Admin\UserDirectoryController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AssetDocumentController;
use App\Http\Controllers\Api\V1\AssetLifecycleController;
use App\Http\Controllers\Api\V1\AssetScanController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\LookupScanController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OtpAuthController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\QuotaController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\StolenListController;
use App\Http\Controllers\Api\V1\StolenListingController;
use App\Http\Controllers\Api\V1\TheftDeclarationFeeController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\WatchAlertController;
use App\Http\Middleware\EnsureAppIsSupported;
use App\Http\Middleware\EnsurePlatformIsWritable;
use App\Http\Middleware\EnsureUserHasBackOfficeAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Sonde de supervision : sans authentification, pour rester interrogeable
    // le jour où l'authentification elle-même est en panne (ST-0904).
    Route::get('health', [HealthController::class, 'show']);

    // Publique : l'application doit pouvoir se configurer avant toute connexion
    Route::get('config/categories', [ConfigController::class, 'categories']);

    // Version minimale exigée : publique et interrogeable AVANT connexion.
    // L'application doit pouvoir afficher son écran de mise à jour sans avoir
    // à se heurter d'abord à un refus au milieu d'un parcours.
    Route::get('config/app', [ConfigController::class, 'app']);

    // Rapport détaillé : l'achat n'exige pas de compte mais exige une
    // identité (règle métier absolue n° 7) ; la lecture ne passe que par le
    // jeton, pour qu'un rapport reçu par SMS s'ouvre sur n'importe quel
    // appareil.
    Route::post('reports/guest-code', [ReportController::class, 'requestGuestCode'])
        ->middleware('throttle:20,1');
    Route::post('assets/{asset}/reports', [ReportController::class, 'purchase'])
        ->middleware('throttle:20,1');
    // Par la RÉFÉRENCE PUBLIQUE : un acheteur ne connaît pas l'identifiant
    // interne du bien, et c'est voulu — le publier permettrait de balayer le
    // registre.
    Route::post('reports', [ReportController::class, 'purchaseByReference'])
        ->middleware('throttle:20,1');
    Route::get('reports/access/{token}', [ReportController::class, 'show']);

    // Webhooks d'opérateurs : signés, idempotents (ST-0806).
    Route::post('webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle']);

    // Consultation de statut : gratuite, anonyme, SANS compte (règle métier
    // absolue n° 1). N'ajouter JAMAIS de middleware d'authentification ici —
    // le contrôleur interroge le garde Sanctum directement, ce qui reconnaît
    // un porteur de jeton (et le dispense du plafond anonyme) sans jamais
    // rendre le jeton nécessaire.
    Route::get('lookup/{identifier}', [LookupController::class, 'show'])
        ->where('identifier', '.*');

    // Lire le numéro sur une carte grise SANS COMPTE, pour le vérifier ensuite.
    // Celui à qui l'on propose une moto sur un parking n'a pas de compte, et
    // c'est lui à qui recopier dix-sept caractères de châssis coûte le plus.
    //
    // Ne consulte RIEN : elle rend l'identifiant lu, pas le statut du bien.
    // Rendre le statut ici serait une consultation qui échappe au journal, aux
    // compteurs de trente jours et au plafond horaire.
    //
    // Double garde : le plafond de dépense (AnonymousScanAllowance, avec sortie
    // par défi) borne le coût chez le fournisseur d'extraction ; le `throttle`
    // borne le débit avant même qu'on lise un octet du fichier.
    /*
     * LA LISTE PUBLIQUE DES BIENS VOLÉS (ST-0805).
     *
     * Publique et sans compte, comme la consultation, et pour la même raison :
     * elle ne sert que si on la parcourt. Un garagiste à qui l'on apporte une
     * moto n'ouvrira pas un compte pour vérifier une intuition.
     *
     * Elle ne montre que ce que des détenteurs ont DEMANDÉ à publier — jamais
     * tous les biens volés. La déclaration protège ; la publication expose, et
     * cela ne se fait pas sans le geste de l'intéressé.
     */
    Route::get('stolen', [StolenListController::class, 'index'])
        ->middleware('throttle:120,1');
    Route::get('stolen/preview', [StolenListController::class, 'preview'])
        ->middleware('throttle:120,1');

    Route::post('lookup/scan', [LookupScanController::class, 'store'])
        ->middleware('throttle:10,10');

    // Le MÊME service, à partir de mots déjà lus sur l'appareil.
    //
    // AUCUNE IMAGE NE CIRCULE : une carte grise porte le nom et l'adresse de
    // son propriétaire, et il n'a jamais fallu l'envoyer pour en extraire
    // dix-sept caractères. La lecture se fait sur le téléphone, hors ligne ;
    // la SÉLECTION reste ici, parce que le chiffre de contrôle du VIN et
    // l'ordre de priorité sont des règles métier — embarquées dans
    // l'application, elles se périmeraient sur des téléphones qui ne se
    // mettent pas à jour.
    //
    // Plus généreux que la route par image, et c'est cohérent : rien n'est
    // dépensé chez personne. Le débit reste borné pour la route elle-même.
    Route::post('lookup/scan/text', [LookupScanController::class, 'text'])
        ->middleware('throttle:60,10');

    Route::prefix('auth')->group(function (): void {
        // Le rythme des envois est déjà borné par destination dans
        // OtpService ; ce plafond-ci borne l'origine de l'appel, pour qu'un
        // automate ne puisse pas balayer des milliers de numéros distincts.
        Route::middleware('throttle:30,1')->group(function (): void {
            Route::post('otp/request', [OtpAuthController::class, 'request']);
            Route::post('otp/verify', [OtpAuthController::class, 'verify']);
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [OtpAuthController::class, 'me']);
            Route::post('logout', [OtpAuthController::class, 'logout']);
        });
    });

    // Toute écriture exige un compte authentifié (règle métier absolue n° 2),
    // et cède la première pendant une maintenance en lecture seule (ST-0904) :
    // enregistrer peut attendre une heure, vérifier un bien avant de payer non.
    Route::middleware(['auth:sanctum', EnsurePlatformIsWritable::class, EnsureAppIsSupported::class])->group(function (): void {
        // Inventaire du porteur du jeton. Sans lui, aucune action n'est
        // atteignable depuis un client : vol, transfert et réclamation passent
        // tous par `/assets/{id}/…`, et un particulier n'avait aucun moyen de
        // connaître l'identifiant interne de ses propres biens.
        Route::get('assets', [AssetController::class, 'index']);
        Route::post('assets', [AssetController::class, 'store']);

        // Pré-remplissage par scan (ST-0202). Le plafond borne la dépense chez
        // le fournisseur d'extraction, qui facture à l'appel : sans lui, un
        // client en boucle épuiserait le quota de toute la plateforme.
        Route::post('assets/scan', [AssetScanController::class, 'store'])
            ->middleware('throttle:20,10');

        // Le même service à partir de mots lus sur l'appareil : aucune image
        // ne circule, rien n'est facturé, donc le débit peut être plus large.
        Route::post('assets/scan/text', [AssetScanController::class, 'text'])
            ->middleware('throttle:60,10');

        // Renforcement de la fiabilité APRÈS l'enregistrement (ST-0207) : c'est
        // ce qui permet au parcours initial de tenir en 90 secondes sans KYC.
        /*
         * METTRE SON BIEN VOLÉ EN AVANT (ST-0805).
         *
         * On ne vend pas la protection — déclarer un vol rend le bien
         * invendable pour qui vérifie son numéro, et cela reste gratuit. On
         * vend la VISIBILITÉ : figurer sur la liste que tout le monde parcourt.
         * Le retrait, lui, est toujours gratuit : une publication qui survit à
         * son motif expose un identifiant pour rien.
         */
        Route::get('assets/{asset}/stolen-listing', [StolenListingController::class, 'show']);
        Route::post('assets/{asset}/stolen-listing', [StolenListingController::class, 'store'])
            ->middleware('throttle:20,10');
        Route::delete('assets/{asset}/stolen-listing', [StolenListingController::class, 'destroy']);

        /*
         * LE PÉAGE DE DÉCLARATION, quand un exploitant en ouvre un (ST-0604).
         *
         * Le tarif vaut ZÉRO par défaut, et ces routes le disent alors sans
         * détour : elles répondent « gratuit, demande ton code ». Elles ne
         * déclarent jamais rien — le statut ne change que derrière le code
         * vérifié, sur `assets/{asset}/stolen`.
         */
        Route::get('assets/{asset}/theft-fee', [TheftDeclarationFeeController::class, 'show']);
        Route::post('assets/{asset}/theft-fee', [TheftDeclarationFeeController::class, 'store'])
            ->middleware('throttle:20,10');

        Route::post('assets/{asset}/documents', [AssetDocumentController::class, 'store']);
        // Revoir ce qu'on a déposé, et la pièce elle-même : sans cela, personne
        // ne sait si sa carte grise est arrivée ni si un agent l'a acceptée.
        Route::get('assets/{asset}/documents', [AssetDocumentController::class, 'index']);
        Route::get('assets/{asset}/documents/{document}/file', [AssetDocumentController::class, 'file']);
        Route::get('assets/{asset}/trust', [AssetDocumentController::class, 'trust']);

        // Envois différés avec reprise (ST-0206, CT-05). Le bien existe déjà :
        // l'enregistrement ne dépend jamais de la qualité du réseau au moment
        // où l'on en a besoin.
        Route::post('uploads', [UploadController::class, 'store']);
        Route::get('uploads/{uuid}', [UploadController::class, 'show']);
        Route::patch('uploads/{uuid}', [UploadController::class, 'append']);

        // Vérification d'identité : friction assumée, exigée seulement pour
        // documenter, réclamer ou transférer (ST-0103, CT-06).
        Route::get('kyc', [KycController::class, 'show']);
        Route::post('kyc', [KycController::class, 'store']);

        // Veille sur un identifiant que l'on a soi-même enregistré (ST-0403).
        Route::get('watch-alerts', [WatchAlertController::class, 'index']);
        Route::post('watch-alerts', [WatchAlertController::class, 'store']);
        Route::delete('watch-alerts/{identifier}', [WatchAlertController::class, 'destroy']);

        // Cycle de vie du bien : vol en un geste, levée, fin de vie (EP-06).
        Route::post('assets/{asset}/stolen', [AssetLifecycleController::class, 'declareStolen']);
        Route::delete('assets/{asset}/stolen', [AssetLifecycleController::class, 'clearStolen']);
        Route::post('assets/{asset}/end-of-life', [AssetLifecycleController::class, 'declareEndOfLife']);

        // Transferts de propriété à double validation (ST-0601 à ST-0603).
        // L'invitation de l'acheteur est un code par SMS, qui ne porte aucun
        // numéro de transfert : sans cette liste, sa confirmation était
        // inatteignable et le parcours s'arrêtait là.
        Route::get('transfers', [TransferController::class, 'index']);
        Route::post('assets/{asset}/transfer', [TransferController::class, 'store']);
        Route::post('transfers/{transfer}/confirm', [TransferController::class, 'confirm']);
        Route::delete('transfers/{transfer}', [TransferController::class, 'destroy']);

        // Réclamation : seul recours d'une victime dont le bien a été
        // enregistré par un tiers (EP-05).
        // Par la RÉFÉRENCE PUBLIQUE : c'est le seul chemin qu'une victime
        // puisse emprunter. Réclamer suppose de désigner le bien d'un autre, et
        // l'identifiant interne de ce bien n'est communiqué à personne.
        Route::post('claims', [ClaimController::class, 'storeByReference']);
        Route::post('assets/{asset}/claims', [ClaimController::class, 'store']);
        Route::post('claims/{claim}/evidences', [ClaimController::class, 'addEvidence']);
        Route::post('claims/{claim}/submit', [ClaimController::class, 'submit']);
        Route::get('claims/{claim}', [ClaimController::class, 'show']);
        Route::post('claims/{claim}/appeal', [ClaimController::class, 'appeal']);

        Route::get('quota', [QuotaController::class, 'show']);

        // Appareils pour le push (ST-1003).
        Route::post('devices', [DeviceTokenController::class, 'store']);
        Route::delete('devices', [DeviceTokenController::class, 'destroy']);

        // Offre flotte B2B (ST-0701 à ST-0703). Import borné : chaque
        // enregistrement prend le verrou de la chaîne d'audit.
        Route::get('fleet/{company}/dashboard', [FleetController::class, 'dashboard']);
        Route::post('fleet/{company}/import', [FleetController::class, 'import']);
        // Suivi d'un import différé : au-delà de 200 lignes, l'import se fait
        // en arrière-plan et le loueur en suit l'avancement.
        Route::get('fleet/{company}/imports/{import}', [FleetController::class, 'importStatus']);
        Route::post('fleet/{company}/rented', [FleetController::class, 'markRented']);

        // Délégation aux collaborateurs (ST-0705) : déléguer sans partager son
        // compte, et garder une traçabilité par acteur qui vaut quelque chose.
        Route::get('fleet/{company}/members', [FleetController::class, 'members']);
        Route::post('fleet/{company}/members', [FleetController::class, 'invite']);
        Route::delete('fleet/{company}/members/{member}', [FleetController::class, 'revoke']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::get('notification-preferences', [NotificationController::class, 'preferences']);
        Route::put('notification-preferences', [NotificationController::class, 'updatePreferences']);
    });

    // Back-office d'instruction : agents ET administrateurs. Revoir un
    // justificatif n'est pas configurer la plateforme.
    Route::prefix('admin')->middleware(['auth:sanctum', EnsureUserHasBackOfficeAccess::class])
        ->group(function (): void {
            // Vue d'ensemble : ce qui attend une décision humaine, avant les
            // volumes. C'est l'écran d'ouverture d'une journée d'instruction —
            // il est donc ouvert aux agents.
            Route::get('overview', [OverviewController::class, 'index']);

            Route::get('kyc', [KycReviewController::class, 'index']);
            Route::post('kyc/{submission}/review', [KycReviewController::class, 'review']);

            // Observabilité : promesses produit tenues ou non, et par où
            // commencer les contrôles (ST-0902, ST-0903).
            Route::get('telemetry', [ObservabilityController::class, 'telemetry']);
            Route::get('fraud-signals', [ObservabilityController::class, 'fraud']);

            Route::get('companies', [CompanyValidationController::class, 'index']);
            Route::post('companies/{company}/validate', [CompanyValidationController::class, 'validateCompany']);

            Route::get('claims', [ClaimReviewController::class, 'index']);
            Route::get('claims/{claim}', [ClaimReviewController::class, 'show']);
            Route::post('claims/{claim}/admissibility', [ClaimReviewController::class, 'admissibility']);
            Route::post('claims/{claim}/decide', [ClaimReviewController::class, 'decide']);
            Route::post('claim-evidences/{evidence}/discard', [ClaimReviewController::class, 'discardEvidence']);

            // Registre des biens : ne dit rien du détenteur (règle n° 4).
            Route::get('assets', [AssetRegistryController::class, 'index']);
            // Le dossier complet d'un bien. Toujours sans identité du détenteur.
            Route::get('assets/{asset}', [AssetRegistryController::class, 'show']);

            // Annuaire des comptes : coordonnées masquées, y compris pour un
            // agent. Suspendre ne suspend jamais la protection des biens.
            Route::get('users', [UserDirectoryController::class, 'index']);
            Route::post('users/{user}/status', [UserDirectoryController::class, 'setStatus']);
            // Corriger une coordonnée mal saisie. Sans ce geste, une adresse
            // fautive enferme son titulaire dehors : le code part dans une
            // boîte qui n'existe pas, et il ne peut pas se corriger lui-même
            // puisqu'il ne peut pas se connecter.
            Route::post('users/{user}/contact', [UserDirectoryController::class, 'setContact']);

            /*
             * Levée d'anonymat sur réquisition (Loi 2013-450).
             *
             * Réservée aux administrateurs — le contrôle est refait dans le
             * contrôleur et ne s'en remet pas au seul routage. Elle vise UNE
             * personne, contre un fondement structuré, et n'ouvre aucun accès
             * durable : la seconde d'après, l'identité est de nouveau
             * inaccessible.
             */
            Route::post('disclosures', [IdentityDisclosureController::class, 'store'])
                ->middleware('throttle:10,60');
            Route::get('disclosures', [IdentityDisclosureController::class, 'index']);

            Route::get('documents', [DocumentReviewController::class, 'index']);
            Route::post('documents/{document}/review', [DocumentReviewController::class, 'review']);

            // Téléchargement authentifié des pièces. Remplace les URL signées :
            // les pièces sont chiffrées au repos — un lien direct rendrait du
            // charabia — et un lien signé est une capacité au porteur, qui
            // ouvre la pièce à quiconque le recopie.
            Route::get('documents/{document}/file', [DocumentReviewController::class, 'file']);
            Route::get('kyc/{submission}/file/{part}', [KycReviewController::class, 'file']);
            Route::post('assets/{asset}/verify', [DocumentReviewController::class, 'verify']);
        });

    // Configuration de la plateforme : administrateurs seulement. Un agent n'a
    // aucune raison de pouvoir rerouter les SMS.
    Route::prefix('admin')->middleware(['auth:sanctum', EnsureUserIsAdmin::class])->group(function (): void {
        /*
         * Piste d'audit : LECTURE SEULE, et réservée aux administrateurs.
         *
         * Aucune écriture n'est offerte — pas seulement parce que les
         * déclencheurs l'interdisent, mais parce qu'un bouton qui échouerait
         * toujours enseignerait qu'une modification est concevable.
         *
         * Fermée aux agents : le journal dit qui a fait quoi, y compris les
         * autres agents. L'instruction d'un dossier ne suppose pas de savoir
         * ce qu'un collègue a décidé hier.
         */
        // Statistiques d'usage et forçage de mise à jour. Réservé aux
        // administrateurs : relever la version minimale met hors service une
        // part du parc installé, ce n'est pas un geste d'instruction.
        Route::get('app-stats', [AppStatsController::class, 'index']);
        Route::put('app-release', [AppStatsController::class, 'setRelease']);

        Route::get('audit-trail', [AuditTrailController::class, 'index']);
        Route::get('audit-trail/export', [AuditTrailController::class, 'export']);

        // Catalogue des catégories : il gouverne ce que TOUTE l'application
        // accepte d'enregistrer, et il est servi aux mobiles sans passage par
        // les magasins. C'est de la configuration de plateforme, pas de
        // l'instruction de dossier. Une catégorie se désactive, jamais ne se
        // supprime — des biens y sont rattachés.
        Route::get('categories', [CategoryAdminController::class, 'index']);
        Route::post('categories/{category}/active', [CategoryAdminController::class, 'setActive']);
        Route::post('categories/{category}/fields', [CategoryAdminController::class, 'addField']);
        Route::post('categories/publish', [CategoryAdminController::class, 'publish']);

        // Équipe : promouvoir fabrique les comptes qui voient les pièces
        // d'identité et lèvent l'anonymat. Un agent ne fabrique pas d'agents.
        Route::get('team', [TeamController::class, 'index']);
        Route::post('team/{user}/role', [TeamController::class, 'setRole']);

        Route::get('sms-provider', [SmsProviderController::class, 'show']);
        Route::put('sms-provider', [SmsProviderController::class, 'update']);
        Route::post('sms-provider/test', [SmsProviderController::class, 'test']);

        Route::get('audit-anchor', [AuditAnchorController::class, 'show']);
        Route::put('audit-anchor', [AuditAnchorController::class, 'update']);
        Route::get('audit-anchor/verify', [AuditAnchorController::class, 'verify']);

        Route::get('platform-state', [PlatformStateController::class, 'show']);
        Route::put('platform-state', [PlatformStateController::class, 'update']);

        Route::get('push-provider', [PushProviderController::class, 'show']);
        Route::put('push-provider', [PushProviderController::class, 'update']);

        // Tarifs de la plateforme. Zéro rend la chose GRATUITE, il ne la
        // désactive pas : c'est ce qui permet d'ouvrir le rapport détaillé ou de
        // lever les frais de dossier sans livraison.
        Route::get('pricing', [PricingController::class, 'show']);
        Route::put('pricing', [PricingController::class, 'update']);
        Route::put('pricing/paystack', [PricingController::class, 'updatePaystack']);
        // Le secret des opérateurs au format maison. Paystack n'en a pas
        // besoin : il signe ses rappels avec sa propre clé secrète.
        Route::put('pricing/webhook-secret', [PricingController::class, 'updateWebhookSecret']);

        Route::get('kyc-provider', [KycProviderController::class, 'show']);
        Route::put('kyc-provider', [KycProviderController::class, 'update']);

        // Destinataire des rapports d'exploitation (ST-0904). Une adresse
        // périmée est un contrôle qui cesse d'exister sans que rien ne le dise.
        // Défi anti-automate (Turnstile). Le désactiver rouvre le balayage du
        // registre : le changement est journalisé dans la chaîne d'audit.
        // Montant des frais de dossier (ST-0501). Zéro lève le blocage : c'est
        // la soupape qui empêche le filtre anti-nuisance de devenir un filtre
        // anti-pauvres. Chaque changement est journalisé.
        Route::get('claim-fee', [ClaimFeeController::class, 'show']);
        Route::put('claim-fee', [ClaimFeeController::class, 'update']);

        Route::get('captcha-provider', [CaptchaProviderController::class, 'show']);
        Route::put('captcha-provider', [CaptchaProviderController::class, 'update']);

        // Adresse de contact pour l'exercice des droits, publiée sur la page
        // de confidentialité. Réglage et non constante : une adresse change,
        // et la loi impose de l'afficher — pas de livrer une version du
        // serveur pour la corriger.
        Route::get('legal-contact', [LegalContactController::class, 'show']);
        Route::put('legal-contact', [LegalContactController::class, 'update']);

        Route::get('ops-recipient', [OpsRecipientController::class, 'show']);
        Route::put('ops-recipient', [OpsRecipientController::class, 'update']);
    });
});
