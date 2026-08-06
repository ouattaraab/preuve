<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\OtpCode;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaystackGateway;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * ST-0806 : les rappels de Paystack, dans SON format.
 *
 * CE QUE CES TESTS AURAIENT DÛ ATTRAPER PLUS TÔT. L'endpoint était écrit pour
 * un format maison — `X-Preuve-Signature`, SHA-256, corps `{reference, status}`.
 * Paystack signe en `x-paystack-signature`, en SHA-512, avec la clé secrète, et
 * poste `{event, data:{reference, status:"success"}}`. Branché tel quel, chaque
 * paiement réel aurait été refusé : l'argent entrait, rien n'était crédité, et
 * personne ne l'aurait su avant une réclamation.
 *
 * LA SIGNATURE EST CALCULÉE SUR LE CORPS BRUT dans ces tests aussi. Signer un
 * tableau ré-encodé validerait un calcul que la production ne fait pas.
 *
 * N'utilise pas RefreshDatabase : la contrepartie écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('payments')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerWebhookPaystack();

    // La clé de test, posée comme l'espace administrateur la pose : chiffrée.
    app(SettingsRepository::class)->setSecret(PaystackGateway::SECRET_SETTING, CLE_PAYSTACK_TEST);
    app(SettingsRepository::class)->fresh();
});
afterEach(fn () => nettoyerWebhookPaystack());

const CLE_PAYSTACK_TEST = 'sk_test_0123456789abcdef0123456789abcdef01234567';

function nettoyerWebhookPaystack(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'payments', 'report_purchases', 'otp_codes',
        'asset_status_history', 'assets', 'app_settings', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    app(SettingsRepository::class)->fresh();
}

function bienVolePaystack(): Asset
{
    $proprietaire = User::create([
        'phone' => '+22507'.random_int(10000000, 99999999),
        'email' => 'payeur'.random_int(1000, 9999).'@exemple.ci',
    ]);
    $numero = 'CC'.random_int(100, 999).'DE';

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'plate',
        'identifier_raw' => $numero,
        'identifier_normalized' => $numero,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha XTZ 125'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Stolen,
        'stolen_declared_at' => now()->subDay(),
        'registered_at' => now()->subMonths(2),
    ]);
}

function paiementOuvert(Asset $bien, PaymentPurpose $motif = PaymentPurpose::TheftListing): Payment
{
    return Payment::create([
        'user_id' => $bien->owner_user_id,
        'purpose' => $motif,
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::Paystack,
        'provider_ref' => 'preuve-'.random_int(1000, 9999).'-'.bin2hex(random_bytes(6)),
        'status' => PaymentStatus::Pending,
    ]);
}

/** Poste un rappel signé comme Paystack le signe : SHA-512 sur le corps BRUT. */
function rappelPaystack(array $charge, ?string $cle = null): TestResponse
{
    $corps = json_encode($charge, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        '/api/v1/webhooks/payments/paystack',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $corps, $cle ?? CLE_PAYSTACK_TEST),
        ],
        $corps,
    );
}

it('CRÉDITE LE SERVICE SUR UN RAPPEL SIGNÉ', function (): void {
    // LE test de la chaîne entière : signature Paystack, état « success »,
    // contrepartie rendue. Sans lui, on encaisse sans rien livrer.
    $bien = bienVolePaystack();
    $paiement = paiementOuvert($bien);

    rappelPaystack([
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success', 'amount' => 100000],
    ])->assertOk();

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        // La contrepartie : le bien paraît sur la liste publique.
        ->and($bien->fresh()?->stolen_listed_at)->not->toBeNull();
});

it('REFUSE UNE SIGNATURE FAUSSE, ET NE CRÉDITE RIEN', function (): void {
    $bien = bienVolePaystack();
    $paiement = paiementOuvert($bien);

    rappelPaystack([
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success'],
    ], cle: 'sk_test_pas_la_bonne_cle_du_tout_0000000000')->assertStatus(401);

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Pending)
        ->and($bien->fresh()?->stolen_listed_at)->toBeNull();
});

it('REFUSE UN RAPPEL SANS SIGNATURE', function (): void {
    // Accepter du non signé reviendrait à laisser quiconque s'attribuer une
    // publication ou un rapport en devinant une référence.
    $paiement = paiementOuvert(bienVolePaystack());

    test()->postJson('/api/v1/webhooks/payments/paystack', [
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success'],
    ])->assertStatus(401);
});

it('REFUSE TOUT TANT QU\'AUCUNE CLÉ N\'EST POSÉE', function (): void {
    // Une plateforme fraîchement déployée ne doit accepter aucun rappel.
    app(SettingsRepository::class)->forget(PaystackGateway::SECRET_SETTING);
    app(SettingsRepository::class)->fresh();

    $paiement = paiementOuvert(bienVolePaystack());

    rappelPaystack([
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success'],
    ])->assertStatus(401);

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Pending);
});

it('ACQUITTE SANS RIEN FAIRE LES ÉVÉNEMENTS QUI NE NOUS CONCERNENT PAS', function (): void {
    // Paystack poste transferts, abonnements et litiges sur la même adresse.
    // Rendre une erreur les ferait retenter pendant des jours, puis désactiver
    // le point de réception — donc nous priver des rappels utiles.
    rappelPaystack([
        'event' => 'transfer.success',
        'data' => ['transfer_code' => 'TRF_xyz', 'status' => 'success'],
    ])->assertOk()->assertJsonPath('ignored', true);
});

it('N\'INVENTE AUCUN ÉTAT SUR UN MOT INCONNU', function (): void {
    // Retomber sur « en attente » ferait reculer une transaction aboutie au
    // premier mot nouveau dans leur API — et `reconcile()` ne rejoue pas un
    // état définitif : la perte serait silencieuse et durable.
    $bien = bienVolePaystack();
    $paiement = paiementOuvert($bien);

    rappelPaystack([
        'event' => 'charge.something',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'quantum'],
    ])->assertOk()->assertJsonPath('ignored', true);

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Pending);
});

it('TRADUIT « abandoned » EN ÉCHEC', function (): void {
    // Le payeur a fermé la page. Le dire permet au client de rouvrir une
    // caisse, là où « en attente » le ferait patienter indéfiniment.
    $paiement = paiementOuvert(bienVolePaystack());

    rappelPaystack([
        'event' => 'charge.failed',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'abandoned'],
    ])->assertOk();

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Failed);
});

it('NE CRÉDITE PAS DEUX FOIS UN RAPPEL REJOUÉ', function (): void {
    // Les opérateurs renvoient le même événement plusieurs fois : c'est le
    // comportement normal d'un réseau peu fiable, pas une anomalie.
    $bien = bienVolePaystack();
    $paiement = paiementOuvert($bien);

    $charge = [
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success'],
    ];

    rappelPaystack($charge)->assertOk();
    $premierePublication = $bien->fresh()?->stolen_listed_at;

    rappelPaystack($charge)->assertOk();

    expect($bien->fresh()?->stolen_listed_at?->toIso8601String())
        ->toBe($premierePublication?->toIso8601String())
        ->and(Payment::query()->count())->toBe(1);
});

it('N\'ENVOIE LE CODE DE DÉCLARATION QU\'APRÈS LE RAPPEL', function (): void {
    // C'est l'ordre demandé : payer, PUIS recevoir le code. Émis avant, il
    // aurait expiré pendant la traversée de la page bancaire.
    $bien = bienVolePaystack();
    $bien->forceFill(['life_status' => LifeStatus::Active, 'stolen_declared_at' => null])->save();
    $paiement = paiementOuvert($bien, PaymentPurpose::TheftDeclaration);

    expect(OtpCode::query()->count())->toBe(0);

    rappelPaystack([
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success'],
    ])->assertOk();

    expect(OtpCode::query()->count())->toBe(1);
});

it('RAMÈNE LE PAYEUR SUR UNE PAGE QUI EXISTE', function (): void {
    // `/paiement/retour` était désigné par le rapport et n'existait nulle part :
    // après avoir réglé, l'acheteur tombait sur un 404.
    $bien = bienVolePaystack();
    $paiement = paiementOuvert($bien);

    test()->get('/paiement/retour?payment='.$paiement->id)
        ->assertOk()
        ->assertSee('En attente de')
        // ON NE DIT JAMAIS « ÉCHEC » AVANT LE RAPPEL : cela ferait repayer
        // quelqu'un qui a déjà payé.
        ->assertSee('Ne payez pas une seconde fois')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('ANNONCE LA CONTREPARTIE UNE FOIS LE RAPPEL REÇU', function (): void {
    $bien = bienVolePaystack();
    $paiement = paiementOuvert($bien);

    rappelPaystack([
        'event' => 'charge.success',
        'data' => ['reference' => $paiement->provider_ref, 'status' => 'success'],
    ])->assertOk();

    test()->get('/paiement/retour?payment='.$paiement->id)
        ->assertOk()
        ->assertSee('Paiement reçu')
        ->assertSee('liste publique');
});

it('NE TOMBE PAS SUR UNE ADRESSE BRICOLÉE', function (): void {
    // Une page publique reçoit tout ce qu'on veut bien lui envoyer.
    test()->get('/paiement/retour')->assertOk();
    test()->get('/paiement/retour?payment[]=1&payment[]=2')->assertOk();
    test()->get('/paiement/retour?payment=999999')->assertOk();
});
