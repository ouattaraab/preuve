<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\ReportPurchase;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Tarifs de la plateforme (ST-0801, ST-0804), réglables sans livraison.
 *
 * CE QUE CES TESTS PROTÈGENT AVANT TOUT, C'EST LE ZÉRO. Un tarif à zéro ne veut
 * pas dire « non réglé » : il veut dire GRATUIT. Un `?:` naïf quelque part
 * rétablirait un prix que l'administrateur vient précisément de supprimer, et
 * personne ne s'en apercevrait avant qu'un utilisateur ne se voie réclamer de
 * l'argent pour un service annoncé gratuit.
 *
 * N'utilise pas RefreshDatabase : les réglages écrivent dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerTarifs();
});
afterEach(fn () => nettoyerTarifs());

function nettoyerTarifs(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'app_settings', 'report_purchases', 'payments', 'notifications',
        'asset_status_history', 'assets', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function adminTarifs(): User
{
    $admin = User::create(['phone' => '+2250700000090', 'full_name' => 'Admin']);
    $admin->forceFill(['role' => UserRole::Admin])->save();

    return $admin;
}

function bienTarifs(): Asset
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
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);
}

it('rend tous les tarifs de la plateforme en un seul endroit', function (): void {
    // Un prix codé ailleurs est un prix qu'on ne peut pas corriger sans livrer.
    Sanctum::actingAs(adminTarifs());

    $tarifs = $this->getJson('/api/v1/admin/pricing')->assertOk()->json('pricing');

    expect(collect($tarifs)->pluck('key')->all())
        ->toContain('report', 'asset_slot', 'claim_fee');
});

it('TRAITE ZÉRO COMME GRATUIT, jamais comme « non réglé »', function (): void {
    // LE test de cet écran. Un repli naïf rétablirait un prix que
    // l'administrateur vient de supprimer.
    Sanctum::actingAs(adminTarifs());

    $this->putJson('/api/v1/admin/pricing', ['pricing' => ['report' => 0]])->assertOk();

    $tarifs = app(PricingService::class)->fresh();

    expect($tarifs->amount('report'))->toBe(0)
        ->and($tarifs->isFree('report'))->toBeTrue();
});

it('journalise l\'ancien ET le nouveau montant', function (): void {
    // Savoir qu'un prix a bougé ne suffit pas à juger s'il a ouvert ou restreint
    // l'accès au service : c'est l'écart qui le dit.
    $admin = adminTarifs();
    Sanctum::actingAs($admin);

    $this->putJson('/api/v1/admin/pricing', ['pricing' => ['report' => 250]])->assertOk();

    $entree = DB::table('audit_log')->where('action', 'pricing.updated')->latest('id')->first();
    $charge = json_decode((string) ($entree->payload ?? '{}'), true);

    expect($charge['changes']['report']['from'])->toBe(1000)
        ->and($charge['changes']['report']['to'])->toBe(250);
});

it('ouvre le rapport SANS paiement quand il est gratuit', function (): void {
    // Ouvrir une transaction de zéro franc ferait échouer l'opérateur, et
    // exiger une carte pour ne rien encaisser serait une friction sans objet.
    $admin = adminTarifs();
    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/admin/pricing', ['pricing' => ['report' => 0]])->assertOk();

    $bien = bienTarifs();
    $acheteur = User::create(['phone' => '+2250700000091']);
    Sanctum::actingAs($acheteur);

    $reponse = $this->postJson('/api/v1/assets/'.$bien->id.'/reports', ['provider' => 'paystack'])
        ->assertStatus(201);

    expect($reponse->json('free'))->toBeTrue()
        ->and($reponse->json('payment'))->toBeNull()
        ->and($reponse->json('access_token'))->not->toBeNull();

    // Aucune ligne de paiement : un paiement de zéro franc dans le journal
    // comptable serait un mensonge.
    expect(DB::table('payments')->count())->toBe(0)
        ->and(ReportPurchase::count())->toBe(1);
});

it('rend le rapport lisible aussitôt, par son jeton', function (): void {
    $admin = adminTarifs();
    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/admin/pricing', ['pricing' => ['report' => 0]])->assertOk();

    $bien = bienTarifs();
    Sanctum::actingAs(User::create(['phone' => '+2250700000092']));

    $jeton = $this->postJson('/api/v1/assets/'.$bien->id.'/reports', ['provider' => 'paystack'])
        ->json('access_token');

    // Le jeton reste la SEULE porte, gratuit ou non : un second chemin de
    // lecture finirait par diverger du premier.
    $this->getJson('/api/v1/reports/access/'.$jeton)->assertOk();
});

it('refuse de payer quand aucune clé d\'opérateur n\'est renseignée', function (): void {
    // Sans clé, la passerelle refuse plutôt que de fabriquer une adresse
    // fantaisiste qui échouerait chez l'utilisateur.
    $admin = adminTarifs();
    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/admin/pricing', ['pricing' => ['report' => 1000]])->assertOk();

    $bien = bienTarifs();
    Sanctum::actingAs(User::create(['phone' => '+2250700000093']));

    $this->postJson('/api/v1/assets/'.$bien->id.'/reports', ['provider' => 'paystack'])
        ->assertStatus(422)
        ->assertJsonPath('errors.provider.0', 'Le paiement par carte n\'est pas encore configuré. Réessayez plus tard.');
});

it('dit à l\'administrateur que la passerelle n\'est pas configurée', function (): void {
    // Fixer un prix sans moyen d'encaisser produirait un bouton « payer » qui
    // échoue, et rien à l'écran ne le laisserait deviner.
    Sanctum::actingAs(adminTarifs());

    $reponse = $this->getJson('/api/v1/admin/pricing')->assertOk();

    expect($reponse->json('paystack.configured'))->toBeFalse()
        ->and($reponse->json('paystack.notice'))->toContain('zéro');
});

it('ne relit jamais la clé secrète, même à un administrateur', function (): void {
    // Une clé secrète qu'on peut réafficher est une clé qui fuit au premier
    // accès indu à la console.
    Sanctum::actingAs(adminTarifs());

    $this->putJson('/api/v1/admin/pricing/paystack', ['secret_key' => 'sk_test_abcdef123456'])
        ->assertOk()
        ->assertJsonPath('paystack.configured', true);

    $contenu = $this->getJson('/api/v1/admin/pricing')->assertOk()->content();

    expect($contenu)->not->toContain('sk_test_abcdef123456');
});

it('ferme les tarifs à qui n\'est pas administrateur', function (): void {
    // Un agent instruit des dossiers ; il ne fixe pas les prix.
    $agent = User::create(['phone' => '+2250700000094']);
    $agent->forceFill(['role' => UserRole::Agent])->save();

    Sanctum::actingAs($agent);

    $this->getJson('/api/v1/admin/pricing')->assertStatus(403);
});
