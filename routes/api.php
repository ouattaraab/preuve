<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuditAnchorController;
use App\Http\Controllers\Api\V1\Admin\ClaimReviewController;
use App\Http\Controllers\Api\V1\Admin\CompanyValidationController;
use App\Http\Controllers\Api\V1\Admin\DocumentReviewController;
use App\Http\Controllers\Api\V1\Admin\KycProviderController;
use App\Http\Controllers\Api\V1\Admin\KycReviewController;
use App\Http\Controllers\Api\V1\Admin\ObservabilityController;
use App\Http\Controllers\Api\V1\Admin\PushProviderController;
use App\Http\Controllers\Api\V1\Admin\SmsProviderController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AssetDocumentController;
use App\Http\Controllers\Api\V1\AssetLifecycleController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OtpAuthController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\QuotaController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\WatchAlertController;
use App\Http\Middleware\EnsureUserHasBackOfficeAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Sonde de supervision : sans authentification, pour rester interrogeable
    // le jour où l'authentification elle-même est en panne (ST-0904).
    Route::get('health', [HealthController::class, 'show']);

    // Publique : l'application doit pouvoir se configurer avant toute connexion
    Route::get('config/categories', [ConfigController::class, 'categories']);

    // Rapport détaillé : l'achat n'exige pas de compte mais exige une
    // identité (règle métier absolue n° 7) ; la lecture ne passe que par le
    // jeton, pour qu'un rapport reçu par SMS s'ouvre sur n'importe quel
    // appareil.
    Route::post('reports/guest-code', [ReportController::class, 'requestGuestCode'])
        ->middleware('throttle:20,1');
    Route::post('assets/{asset}/reports', [ReportController::class, 'purchase'])
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

    // Toute écriture exige un compte authentifié (règle métier absolue n° 2).
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('assets', [AssetController::class, 'store']);

        // Renforcement de la fiabilité APRÈS l'enregistrement (ST-0207) : c'est
        // ce qui permet au parcours initial de tenir en 90 secondes sans KYC.
        Route::post('assets/{asset}/documents', [AssetDocumentController::class, 'store']);
        Route::get('assets/{asset}/trust', [AssetDocumentController::class, 'trust']);

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
        Route::post('assets/{asset}/transfer', [TransferController::class, 'store']);
        Route::post('transfers/{transfer}/confirm', [TransferController::class, 'confirm']);
        Route::delete('transfers/{transfer}', [TransferController::class, 'destroy']);

        // Réclamation : seul recours d'une victime dont le bien a été
        // enregistré par un tiers (EP-05).
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

            Route::get('documents', [DocumentReviewController::class, 'index']);
            Route::post('documents/{document}/review', [DocumentReviewController::class, 'review']);
            Route::post('assets/{asset}/verify', [DocumentReviewController::class, 'verify']);
        });

    // Configuration de la plateforme : administrateurs seulement. Un agent n'a
    // aucune raison de pouvoir rerouter les SMS.
    Route::prefix('admin')->middleware(['auth:sanctum', EnsureUserIsAdmin::class])->group(function (): void {
        Route::get('sms-provider', [SmsProviderController::class, 'show']);
        Route::put('sms-provider', [SmsProviderController::class, 'update']);
        Route::post('sms-provider/test', [SmsProviderController::class, 'test']);

        Route::get('audit-anchor', [AuditAnchorController::class, 'show']);
        Route::put('audit-anchor', [AuditAnchorController::class, 'update']);
        Route::get('audit-anchor/verify', [AuditAnchorController::class, 'verify']);

        Route::get('push-provider', [PushProviderController::class, 'show']);
        Route::put('push-provider', [PushProviderController::class, 'update']);

        Route::get('kyc-provider', [KycProviderController::class, 'show']);
        Route::put('kyc-provider', [KycProviderController::class, 'update']);
    });
});
