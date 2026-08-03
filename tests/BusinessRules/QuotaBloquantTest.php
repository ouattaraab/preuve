<?php

declare(strict_types=1);

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Services\AssetRegistrationService;
use App\Services\CategoryRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Quota d'enregistrement bloquant (ST-0804, décision produit du 03/08/2026).
 *
 * Le quota ne bloquait pas : il proposait. L'arbitrage a été rendu dans l'autre
 * sens. Ce fichier verrouille ce que ce choix implique — et ce qu'il ne doit
 * PAS emporter avec lui.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('payments')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerQuotaBloquant();
    categorieQuotaBloquant();
});
afterEach(fn () => nettoyerQuotaBloquant());

function nettoyerQuotaBloquant(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'payments', 'asset_status_history', 'assets',
        'category_fields', 'asset_categories', 'companies',
        'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieQuotaBloquant(): void
{
    $categorie = AssetCategory::create([
        'key' => 'moto', 'name' => 'Moto', 'icon' => '🛵', 'position' => 1, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'chassis', 'label' => 'Châssis',
        'type' => 'identifier', 'is_required' => true, 'is_canonical_identifier' => true, 'position' => 1,
    ]);

    app(CategoryRegistry::class)->publish();
}

/** VIN valides et distincts : l'unicité active est une règle absolue. */
function vinsValides(): array
{
    return ['1M8GDM9AXKP042788', 'JHMCM56557C404453', '1HGCM82633A004352', 'JH4TB2H26CC000000'];
}

function enregistrerViaApi(string $vin): TestResponse
{
    return test()->postJson('/api/v1/assets', [
        'category' => 'moto',
        'attributes' => ['chassis' => $vin],
    ]);
}

it('refuse le quatrième enregistrement en 402', function (): void {
    // 402 et non 422 : il n'y a rien à corriger dans la demande, elle est
    // valide et le restera. Le client doit router vers le paiement, pas vers
    // le formulaire.
    Sanctum::actingAs(User::create(['phone' => '+2250701020304']));

    $vins = vinsValides();

    foreach (array_slice($vins, 0, 3) as $vin) {
        enregistrerViaApi($vin)->assertStatus(201);
    }

    $refus = enregistrerViaApi($vins[3])->assertStatus(402);

    expect($refus->json('quota.remaining'))->toBe(0)
        ->and($refus->json('quota.next_slot_price_fcfa'))->toBe((int) config('preuve.asset_slot_price_fcfa'))
        // Le refus dit la sortie : sans elle il ne reste que l'abandon.
        ->and($refus->json('message'))->toContain('place');
});

it('n\'écrit rien du tout quand il refuse', function (): void {
    // Ni bien, ni entrée d'audit : une tentative refusée n'a pas eu lieu, et
    // la chaîne d'audit est inaltérable — y faire entrer des non-événements la
    // salirait pour toujours.
    Sanctum::actingAs(User::create(['phone' => '+2250701020305']));

    $vins = vinsValides();

    foreach (array_slice($vins, 0, 3) as $vin) {
        enregistrerViaApi($vin)->assertStatus(201);
    }

    $biens = DB::table('assets')->count();
    $audit = DB::table('audit_log')->count();

    enregistrerViaApi($vins[3])->assertStatus(402);

    expect(DB::table('assets')->count())->toBe($biens)
        ->and(DB::table('audit_log')->count())->toBe($audit);
});

it('rouvre l\'enregistrement dès qu\'une place est payée', function (): void {
    $proprietaire = User::create(['phone' => '+2250701020306']);
    Sanctum::actingAs($proprietaire);

    $vins = vinsValides();

    foreach (array_slice($vins, 0, 3) as $vin) {
        enregistrerViaApi($vin)->assertStatus(201);
    }

    enregistrerViaApi($vins[3])->assertStatus(402);

    Payment::create([
        'user_id' => $proprietaire->id,
        'purpose' => PaymentPurpose::AssetSlot,
        'provider' => 'paystack',
        'provider_ref' => 'ref-'.bin2hex(random_bytes(6)),
        'amount_fcfa' => (int) config('preuve.asset_slot_price_fcfa'),
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now(),
    ]);

    enregistrerViaApi($vins[3])->assertStatus(201);
});

it('ne bloque pas une flotte, qui relève de son abonnement', function (): void {
    // Une flotte est régie par ST-0805 et la suspension douce, qui ne retire
    // jamais la protection acquise. Y appliquer en plus le quota personnel
    // refuserait deux fois la même chose.
    $gerant = User::create(['phone' => '+2250701020307']);

    $societe = Company::create([
        'owner_user_id' => $gerant->id,
        'legal_name' => 'Loueur Test',
        'rccm_number' => 'CI-ABJ-'.random_int(100000, 999999),
        'status' => 'validated',
    ]);

    $service = app(AssetRegistrationService::class);

    // Bien au-delà des trois places gratuites d'un particulier.
    foreach (vinsValides() as $vin) {
        $service->register($gerant, 'moto', ['chassis' => $vin], companyId: $societe->id);
    }

    expect(DB::table('assets')->count())->toBe(4);
});

it('libère une place quand un bien est archivé', function (): void {
    // Un bien cédé ne pèse plus sur le quota de son ancien détenteur, qui n'en
    // tire plus aucun service.
    $proprietaire = User::create(['phone' => '+2250701020308']);
    Sanctum::actingAs($proprietaire);

    $vins = vinsValides();

    foreach (array_slice($vins, 0, 3) as $vin) {
        enregistrerViaApi($vin)->assertStatus(201);
    }

    enregistrerViaApi($vins[3])->assertStatus(402);

    // Archivage : `active_flag` à NULL, comme après un transfert.
    DB::table('assets')->where('identifier_normalized', $vins[0])->update(['active_flag' => null]);

    enregistrerViaApi($vins[3])->assertStatus(201);
});

it('laisse la consultation publique intacte', function (): void {
    // Le quota touche l'écriture, jamais la règle métier absolue n° 1 : la
    // consultation reste gratuite, anonyme et sans compte, y compris pour qui
    // a épuisé ses places.
    Sanctum::actingAs(User::create(['phone' => '+2250701020309']));

    foreach (array_slice(vinsValides(), 0, 3) as $vin) {
        enregistrerViaApi($vin)->assertStatus(201);
    }

    // Le garde Sanctum mémorise l'utilisateur entre deux requêtes du même
    // test : sans cette remise à zéro, la consultation serait faite en tant
    // qu'authentifié et n'éprouverait pas l'accès anonyme.
    app('auth')->forgetGuards();

    test()->getJson('/api/v1/lookup/'.vinsValides()[0])
        ->assertOk()
        ->assertJsonPath('found', true);
});
