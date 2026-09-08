<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\OtpCode;
use App\Models\Payment;
use App\Models\User;
use App\Services\Otp\OtpSender;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0604 : le péage de déclaration de vol, quand un exploitant en ouvre un.
 *
 * CE QUE CES TESTS VERROUILLENT AVANT TOUT, C'EST LE DÉFAUT À ZÉRO. Un tarif
 * qui s'activerait par accident — réglage effacé, configuration absente,
 * migration incomplète — fermerait la déclaration de vol à tout le monde en
 * silence, et le registre cesserait d'apprendre les vols. C'est la panne la
 * plus grave que ce dispositif puisse produire, et elle ne ferait aucun bruit :
 * personne ne signale qu'il a renoncé.
 *
 * L'ORDRE PAYER → CODE EST L'AUTRE INVARIANT. Le code part quand l'opérateur a
 * confirmé, jamais avant : émis en amont, il aurait expiré pendant la traversée
 * de la page bancaire.
 *
 * N'utilise pas RefreshDatabase : la chaîne d'audit refuse toute transaction
 * englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('assets')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerPeageVol();

    // Un capteur À NOUS, et non celui d'un autre fichier : une classe déclarée
    // ailleurs n'existe pas quand on lance ce fichier seul.
    $this->sender = new CapteurPeage;
    $this->app->instance(OtpSender::class, $this->sender);
});
afterEach(fn () => nettoyerPeageVol());

function nettoyerPeageVol(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'payments', 'otp_codes', 'asset_status_history',
        'assets', 'app_settings', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    app(SettingsRepository::class)->fresh();
}

function detenteurPeage(): User
{
    return User::create([
        'phone' => '+22507'.random_int(10000000, 99999999),
        'email' => 'victime'.random_int(1000, 9999).'@exemple.ci',
    ]);
}

function bienSainPeage(User $proprietaire): Asset
{
    $numero = 'BB'.random_int(100, 999).'CD';

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
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(3),
    ]);
}

function ouvrirLePeage(int $montant): void
{
    app(SettingsRepository::class)->set('pricing.theft_declaration_fcfa', $montant);
    app(SettingsRepository::class)->fresh();
}

/** Retient les codes émis, pour les relire comme le ferait le destinataire. */
class CapteurPeage implements OtpSender
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

it('LAISSE DÉCLARER GRATUITEMENT TANT QUE PERSONNE N\'A OUVERT LE PÉAGE', function (): void {
    // LE test de ce dispositif. Le jour où ce test tombe, la déclaration de vol
    // est fermée par accident à tous les utilisateurs — et le registre cesse
    // d'apprendre les vols sans que rien ne le signale.
    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);
    Sanctum::actingAs($detenteur);

    test()->getJson('/api/v1/assets/'.$bien->id.'/theft-fee')
        ->assertOk()
        ->assertJsonPath('free', true)
        ->assertJsonPath('fee_fcfa', 0)
        ->assertJsonPath('paid', true);
});

it('REFUSE LA DÉCLARATION NON RÉGLÉE, ET DIT LE MONTANT', function (): void {
    ouvrirLePeage(1000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);
    Sanctum::actingAs($detenteur);

    $reponse = test()->postJson('/api/v1/assets/'.$bien->id.'/stolen', ['code' => '123456'])
        // 402 ET NON 422 : l'utilisateur n'a rien rempli de travers.
        ->assertStatus(402)
        ->assertJsonPath('payment_required', true)
        ->assertJsonPath('fee_fcfa', 1000);

    expect($reponse->json('message'))->toContain('1 000');

    // ET LE BIEN N'A PAS BOUGÉ : un refus qui aurait quand même déclaré serait
    // pire que pas de péage du tout.
    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('N\'ÉMET AUCUN CODE AVANT LE PAIEMENT', function (): void {
    // Un code émis à l'ouverture aurait expiré pendant la traversée de la page
    // bancaire : l'utilisateur serait revenu payé, et bloqué.
    ouvrirLePeage(1000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);
    Sanctum::actingAs($detenteur);

    test()->postJson('/api/v1/assets/'.$bien->id.'/stolen', ['code' => '123456'])->assertStatus(402);

    expect(OtpCode::query()->count())->toBe(0);
});

it('ENVOIE LE CODE QUAND L\'OPÉRATEUR A CONFIRMÉ', function (): void {
    ouvrirLePeage(1000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);

    $paiement = Payment::create([
        'user_id' => $detenteur->id,
        'purpose' => PaymentPurpose::TheftDeclaration,
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::Paystack,
        'provider_ref' => 'ref-'.bin2hex(random_bytes(4)),
        'status' => PaymentStatus::Succeeded,
    ]);

    app(PaymentService::class)->fulfill($paiement);

    $code = OtpCode::query()->where('purpose', OtpPurpose::SensitiveAction)->first();

    expect($code)->not->toBeNull()
        // Au dossier, jamais soumis : une destination reçue de la requête
        // laisserait détourner le code.
        ->and($code?->destination)->toBe($detenteur->phone);
});

it('LAISSE DÉCLARER UNE FOIS LE PAIEMENT REÇU, SANS REPAYER', function (): void {
    ouvrirLePeage(1000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);

    Payment::create([
        'user_id' => $detenteur->id,
        'purpose' => PaymentPurpose::TheftDeclaration,
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::Paystack,
        'provider_ref' => 'ref-'.bin2hex(random_bytes(4)),
        'status' => PaymentStatus::Succeeded,
    ]);

    $code = codeValidePour($detenteur);
    Sanctum::actingAs($detenteur);

    test()->postJson('/api/v1/assets/'.$bien->id.'/stolen', ['code' => $code])->assertOk();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

it('NE FAIT PAS PAYER DEUX FOIS APRÈS UN CODE EXPIRÉ', function (): void {
    // Le droit acquis vit dans `payments`, pas dans le code : l'application
    // fermée trop tôt, un code périmé, un second essai le lendemain — aucun de
    // ces incidents ne doit faire payer une seconde fois.
    ouvrirLePeage(1000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);

    Payment::create([
        'user_id' => $detenteur->id,
        'purpose' => PaymentPurpose::TheftDeclaration,
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::Paystack,
        'provider_ref' => 'ref-'.bin2hex(random_bytes(4)),
        'status' => PaymentStatus::Succeeded,
    ]);

    Sanctum::actingAs($detenteur);

    test()->getJson('/api/v1/assets/'.$bien->id.'/theft-fee')
        ->assertOk()
        ->assertJsonPath('paid', true);

    test()->postJson('/api/v1/assets/'.$bien->id.'/theft-fee')
        ->assertOk()
        ->assertJsonPath('payment_required', false);
});

it('NE FAIT PAS PAYER POUR UN BIEN DÉJÀ DÉCLARÉ VOLÉ', function (): void {
    ouvrirLePeage(1000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);
    $bien->forceFill(['life_status' => LifeStatus::Stolen])->save();

    Sanctum::actingAs($detenteur);

    test()->postJson('/api/v1/assets/'.$bien->id.'/theft-fee')->assertStatus(422);

    expect(Payment::query()->count())->toBe(0);
});

it('NE LAISSE PERSONNE PAYER POUR LE BIEN D\'UN AUTRE', function (): void {
    ouvrirLePeage(1000);

    $bien = bienSainPeage(detenteurPeage());
    Sanctum::actingAs(detenteurPeage());

    // 404 et non 403 : distinguer les deux permettrait d'énumérer le registre.
    test()->getJson('/api/v1/assets/'.$bien->id.'/theft-fee')->assertNotFound();
    test()->postJson('/api/v1/assets/'.$bien->id.'/theft-fee')->assertNotFound();
});

it('N\'OUVRE RIEN À UN VISITEUR ANONYME', function (): void {
    $bien = bienSainPeage(detenteurPeage());

    test()->getJson('/api/v1/assets/'.$bien->id.'/theft-fee')->assertUnauthorized();
});

it('LAISSE LEVER UN VOL SANS RIEN PAYER', function (): void {
    // Le péage porte sur la déclaration. Faire payer la LEVÉE enfermerait un
    // bien retrouvé dans un statut faux, et pousserait à ne pas la lever —
    // c'est-à-dire à polluer le registre.
    ouvrirLePeage(5000);

    $detenteur = detenteurPeage();
    $bien = bienSainPeage($detenteur);
    $bien->forceFill([
        'life_status' => LifeStatus::Stolen,
        'stolen_declared_at' => now()->subDay(),
    ])->save();

    $code = codeValidePour($detenteur);
    Sanctum::actingAs($detenteur);

    test()->deleteJson('/api/v1/assets/'.$bien->id.'/stolen', ['code' => $code])->assertOk();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

/**
 * Un code réellement émis par le service : on ne fabrique pas de faux jeton.
 *
 * `otpDestination()` et non `->phone` : un compte ouvert par adresse n'a pas de
 * numéro, et un harnais qui interrogerait la mauvaise colonne éprouverait autre
 * chose que la production.
 */
function codeValidePour(User $titulaire): string
{
    $destination = $titulaire->otpDestination() ?? '';

    app(OtpService::class)->request($destination, OtpPurpose::SensitiveAction);

    return test()->sender->pour($destination);
}
