<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Models\Asset;
use App\Models\Payment;
use App\Models\ReportPurchase;
use App\Models\User;
use App\Services\Otp\OtpSender;
use App\Services\PaystackGateway;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/** ST-0801, ST-0802, ST-0806 : parcours d'achat et webhooks. */
beforeEach(function (): void {
    if (! Schema::hasTable('payments')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerRapports();

    $this->sender = new CapteurRapport;
    $this->app->instance(OtpSender::class, $this->sender);
    $this->settings = app(SettingsRepository::class)->fresh();
});
afterEach(fn () => nettoyerRapports());

function nettoyerRapports(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'report_purchases', 'payments', 'app_settings',
        'asset_status_history', 'assets', 'otp_codes', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienPourRapport(): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Documented,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(6),
    ]);
}

it('achète un rapport sans compte, après vérification du numéro', function (): void {
    // ST-0802 : le code est vérifié AVANT paiement — après, l'accès serait
    // déjà ouvert.
    $bien = bienPourRapport();

    $this->postJson('/api/v1/reports/guest-code', ['phone' => '0788888888'])->assertOk();

    $this->postJson("/api/v1/assets/{$bien->id}/reports", [
        'provider' => 'pawapay_wave',
        'buyer_name' => 'Yao N.',
        'buyer_email' => 'yao@example.ci',
        'buyer_phone' => '0788888888',
        'code' => $this->sender->pour('+2250788888888'),
    ])->assertStatus(201)->assertJsonPath('payment.status', 'pending');

    expect(Payment::sole()->buyer_email)->toBe('yao@example.ci');
});

it('refuse l\'achat sans compte si le code est faux', function (): void {
    $bien = bienPourRapport();
    $this->postJson('/api/v1/reports/guest-code', ['phone' => '0788888888'])->assertOk();

    $this->postJson("/api/v1/assets/{$bien->id}/reports", [
        'provider' => 'pawapay_wave',
        'buyer_name' => 'Yao N.',
        'buyer_email' => 'yao@example.ci',
        'buyer_phone' => '0788888888',
        'code' => '000000',
    ])->assertStatus(422);

    expect(Payment::count())->toBe(0);
});

it('achète sans code quand l\'acheteur est connecté, et rend où payer', function (): void {
    // LE PARCOURS COMPLET : ouvrir le paiement ne suffit pas, il faut rendre
    // l'ADRESSE de règlement. Sans elle, l'acheteur était prié de « régler
    // auprès de l'opérateur » sans savoir où — c'est-à-dire nulle part.
    app(SettingsRepository::class)->setSecret(PaystackGateway::SECRET_SETTING, 'sk_test_abcdef123456');

    Http::fake([
        'api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz789'],
        ]),
    ]);

    $bien = bienPourRapport();
    Sanctum::actingAs(User::create(['phone' => '+2250766666666']));

    $reponse = $this->postJson("/api/v1/assets/{$bien->id}/reports", ['provider' => 'paystack'])
        ->assertStatus(201);

    expect($reponse->json('checkout_url'))->toBe('https://checkout.paystack.com/xyz789')
        ->and(Payment::sole()->user_id)->not->toBeNull()
        // La référence est posée AVANT la redirection : c'est elle que le
        // webhook rapprochera, et l'unicité rend un rejeu inoffensif.
        ->and(Payment::sole()->provider_ref)->toStartWith('preuve-');
});

it('n\'accorde RIEN au retour : seul le webhook fait foi', function (): void {
    // Un client qui revient en annonçant « c'est payé » ne prouve rien. Sans
    // cette règle, il suffirait de rappeler l'adresse de retour à la main pour
    // obtenir un rapport sans payer.
    app(SettingsRepository::class)->setSecret(PaystackGateway::SECRET_SETTING, 'sk_test_abcdef123456');

    Http::fake([
        'api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz789'],
        ]),
    ]);

    $bien = bienPourRapport();
    Sanctum::actingAs(User::create(['phone' => '+2250766666667']));

    $this->postJson("/api/v1/assets/{$bien->id}/reports", ['provider' => 'paystack'])->assertStatus(201);

    // Le paiement reste en attente, et aucun accès n'a été accordé.
    expect(Payment::sole()->status)->toBe(PaymentStatus::Pending)
        ->and(ReportPurchase::count())->toBe(0);
});

it('sert le rapport par son jeton, sans authentification', function (): void {
    // Le jeton EST le droit d'accès : il a été acheté par une personne
    // identifiée, et doit s'ouvrir sur n'importe quel appareil.
    $bien = bienPourRapport();
    $achat = ReportPurchase::create([
        'asset_id' => $bien->id,
        'payment_id' => Payment::create([
            'user_id' => User::create(['phone' => '+2250755555555'])->id,
            'purpose' => 'detailed_report',
            'related_id' => $bien->id,
            'amount_fcfa' => 1000,
            'provider' => PaymentProvider::Paystack,
            'status' => PaymentStatus::Succeeded,
        ])->id,
        'access_token' => str_repeat('a', 40),
        'expires_at' => now()->addDays(30),
    ]);

    $this->getJson('/api/v1/reports/access/'.$achat->access_token)
        ->assertOk()
        ->assertJsonPath('asset.public_ref', $bien->public_ref)
        ->assertJsonStructure(['ownership' => ['holders_count'], 'history', 'incidents']);
});

it('répond 404 sur un jeton inconnu', function (): void {
    $this->getJson('/api/v1/reports/access/'.str_repeat('z', 40))->assertStatus(404);
});

it('refuse un webhook non signé', function (): void {
    // Accepter des événements non signés laisserait quiconque s'attribuer des
    // rapports gratuitement.
    $this->postJson('/api/v1/webhooks/payments/pawapay_wave', [
        'reference' => 'REF-1',
        'status' => 'succeeded',
    ])->assertStatus(401);
});

it('crédite le rapport sur webhook signé', function (): void {
    $this->settings->set(PaymentWebhookController::SECRET_SETTING, 'secret-partage');
    $bien = bienPourRapport();

    $paiement = Payment::create([
        'user_id' => User::create(['phone' => '+2250744444444'])->id,
        'purpose' => 'detailed_report',
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'REF-OK',
        'status' => PaymentStatus::Pending,
    ]);

    $corps = ['reference' => 'REF-OK', 'status' => 'succeeded'];

    $this->withHeader('X-Preuve-Signature', hash_hmac('sha256', json_encode($corps), 'secret-partage'))
        ->postJson('/api/v1/webhooks/payments/pawapay_wave', $corps)
        ->assertOk()
        ->assertJsonPath('received', true);

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(ReportPurchase::count())->toBe(1);
});

it('reste idempotent sur webhook rejoué', function (): void {
    $this->settings->set(PaymentWebhookController::SECRET_SETTING, 'secret-partage');
    $bien = bienPourRapport();

    Payment::create([
        'user_id' => User::create(['phone' => '+2250733333333'])->id,
        'purpose' => 'detailed_report',
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'REF-REJOUE',
        'status' => PaymentStatus::Pending,
    ]);

    $corps = ['reference' => 'REF-REJOUE', 'status' => 'succeeded'];
    $signature = hash_hmac('sha256', json_encode($corps), 'secret-partage');

    foreach (range(1, 3) as $envoi) {
        $this->withHeader('X-Preuve-Signature', $signature)
            ->postJson('/api/v1/webhooks/payments/pawapay_wave', $corps)
            ->assertOk();
    }

    expect(ReportPurchase::count())->toBe(1);
});

it('accuse réception d\'un événement inconnu sans le traiter', function (): void {
    // Un opérateur qui reçoit une erreur réessaie parfois pendant des jours,
    // alors qu'une référence qui ne nous concerne pas ne deviendra jamais
    // valide.
    $this->settings->set(PaymentWebhookController::SECRET_SETTING, 'secret-partage');

    $corps = ['reference' => 'REF-ETRANGERE', 'status' => 'succeeded'];

    $this->withHeader('X-Preuve-Signature', hash_hmac('sha256', json_encode($corps), 'secret-partage'))
        ->postJson('/api/v1/webhooks/payments/paystack', $corps)
        ->assertOk();

    expect(Payment::count())->toBe(0);
});

it('refuse un opérateur inconnu', function (): void {
    $this->settings->set(PaymentWebhookController::SECRET_SETTING, 'secret-partage');

    $this->postJson('/api/v1/webhooks/payments/cinetpay', ['reference' => 'X', 'status' => 'succeeded'])
        ->assertStatus(404);
});

/** Capte les codes émis, par destination. */
class CapteurRapport implements OtpSender
{
    /** @var array<string, string> */
    public array $codes = [];

    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        $this->codes[$destination] = $code;
    }

    public function pour(string $destination): string
    {
        return $this->codes[$destination] ?? '';
    }
}
