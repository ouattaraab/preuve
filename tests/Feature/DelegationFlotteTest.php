<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\CompanyMemberService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0705 : déléguer sans partager son compte.
 *
 * Le besoin réel : un loueur qui ne peut pas déléguer partage son téléphone
 * avec ses agents de comptoir. Toutes les actions apparaissent alors sous son
 * nom, et la traçabilité par acteur ne vaut plus rien en pratique.
 *
 * N'utilise pas RefreshDatabase : les invitations écrivent dans la chaîne
 * d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('company_members')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerDelegation();
    $this->membres = app(CompanyMemberService::class);
});
afterEach(fn () => nettoyerDelegation());

function nettoyerDelegation(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'lookups', 'company_members', 'asset_status_history',
        'assets', 'companies', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function loueurAvecFlotte(): array
{
    $gerant = User::create(['phone' => '+2250700000001']);
    $gerant->forceFill(['kyc_status' => KycStatus::Verified->value])->save();

    $societe = Company::create([
        'owner_user_id' => $gerant->id,
        'legal_name' => 'Abidjan Location',
        'rccm_number' => 'CI-ABJ-'.random_int(10000, 99999),
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
        'free_fleet_quota' => 3,
    ]);

    $plaque = 'DELEG'.random_int(100000, 999999);

    $vehicule = Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $gerant->id,
        'company_id' => $societe->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'plate',
        'identifier_raw' => $plaque,
        'identifier_normalized' => $plaque,
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);

    return [$gerant, $societe, $vehicule];
}

it('donne un accès au représentant légal sans ligne de délégation', function (): void {
    // Il EST la société : son accès ne dépend d'aucune ligne.
    [$gerant, $societe] = loueurAvecFlotte();

    expect($this->membres->roleOf($societe, $gerant))->toBe(CompanyRole::Admin)
        ->and(CompanyMember::count())->toBe(0);
});

it('invite un collaborateur qui n\'a pas encore de compte', function (): void {
    // Un agent embauché lundi ne s'inscrira pas avant d'en avoir besoin.
    [$gerant, $societe] = loueurAvecFlotte();

    $membre = $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);

    expect($membre->user_id)->toBeNull()
        ->and($membre->invited_phone)->toBe('+2250788888888')
        ->and(User::count())->toBe(1);
});

it('rattache le compte à la première connexion', function (): void {
    [$gerant, $societe] = loueurAvecFlotte();
    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);

    $agent = User::create(['phone' => '+2250788888888']);

    expect($this->membres->roleOf($societe, $agent))->toBe(CompanyRole::Operator)
        ->and(CompanyMember::sole()->user_id)->toBe($agent->id);
});

it('laisse un opérateur marquer les véhicules', function (): void {
    [$gerant, $societe, $vehicule] = loueurAvecFlotte();
    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);
    $agent = User::create(['phone' => '+2250788888888']);

    Sanctum::actingAs($agent);

    $this->postJson("/api/v1/fleet/{$societe->id}/rented", [
        'asset_ids' => [$vehicule->id],
        'rented' => true,
    ])->assertOk()->assertJsonPath('report.updated', 1);

    expect($vehicule->fresh()?->life_status)->toBe(LifeStatus::Rented);
});

it('trace l\'action au nom du collaborateur, pas du gérant', function (): void {
    // C'est tout l'objet de la délégation : sans elle, l'action apparaîtrait
    // sous le nom du gérant, et la traçabilité ne vaudrait rien.
    [$gerant, $societe, $vehicule] = loueurAvecFlotte();
    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);
    $agent = User::create(['phone' => '+2250788888888']);

    Sanctum::actingAs($agent);
    $this->postJson("/api/v1/fleet/{$societe->id}/rented", [
        'asset_ids' => [$vehicule->id],
        'rented' => true,
    ])->assertOk();

    $transition = AuditLog::where('action', 'asset.status_transition')->sole();

    expect($transition->actor_id)->toBe($agent->id)
        ->and($transition->actor_id)->not->toBe($gerant->id);
});

it('interdit à un opérateur de gérer les accès', function (): void {
    // Un opérateur de comptoir marque des véhicules, il n'invite personne.
    [$gerant, $societe] = loueurAvecFlotte();
    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);
    $agent = User::create(['phone' => '+2250788888888']);

    Sanctum::actingAs($agent);

    $this->postJson("/api/v1/fleet/{$societe->id}/members", [
        'phone' => '0777777777',
        'role' => 'operator',
    ])->assertStatus(422);
});

it('laisse un administrateur de flotte inviter', function (): void {
    [$gerant, $societe] = loueurAvecFlotte();
    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Admin);
    $adjoint = User::create(['phone' => '+2250788888888']);

    Sanctum::actingAs($adjoint);

    $this->postJson("/api/v1/fleet/{$societe->id}/members", [
        'phone' => '0777777777',
        'role' => 'operator',
    ])->assertStatus(201);

    expect(CompanyMember::count())->toBe(2);
});

it('ne délègue jamais les actes de propriété', function (): void {
    // Confondre « gérer la flotte » et « disposer des biens » ferait d'un
    // téléphone d'employé volé une perte de parc.
    expect(CompanyRole::Admin->disposesOfAssets())->toBeFalse()
        ->and(CompanyRole::Operator->disposesOfAssets())->toBeFalse()
        ->and(CompanyRole::Admin->managesMembers())->toBeTrue()
        ->and(CompanyRole::Operator->managesMembers())->toBeFalse();
});

it('révoque un accès sans effacer la trace', function (): void {
    // Savoir qui a eu accès, et quand, fait partie de la traçabilité que la
    // délégation existe pour préserver.
    [$gerant, $societe] = loueurAvecFlotte();
    $membre = $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);
    $agent = User::create(['phone' => '+2250788888888']);

    $this->membres->revoke($societe, $gerant, $membre->id);

    expect($this->membres->roleOf($societe, $agent))->toBeNull()
        ->and(CompanyMember::count())->toBe(1)
        ->and(CompanyMember::sole()->is_active)->toBeFalse();
});

it('ferme la flotte à un collaborateur révoqué', function (): void {
    [$gerant, $societe, $vehicule] = loueurAvecFlotte();
    $membre = $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);
    $agent = User::create(['phone' => '+2250788888888']);
    $this->membres->revoke($societe, $gerant, $membre->id);

    Sanctum::actingAs($agent);

    $this->getJson("/api/v1/fleet/{$societe->id}/dashboard")->assertStatus(404);
});

it('n\'écrit pas le numéro invité dans la chaîne d\'audit', function (): void {
    [$gerant, $societe] = loueurAvecFlotte();

    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);

    $entree = AuditLog::where('action', 'company.member_invited')->sole();

    expect(json_encode($entree->payload))->not->toContain('2250788888888')
        ->and($entree->payload['role'] ?? null)->toBe('operator');
});

it('masque les numéros dans la liste des accès', function (): void {
    // Un administrateur doit reconnaître qui il a invité, sans que la liste
    // devienne un carnet d'adresses exportable.
    [$gerant, $societe] = loueurAvecFlotte();
    $this->membres->invite($societe, $gerant, '0788888888', CompanyRole::Operator);

    Sanctum::actingAs($gerant);

    $reponse = $this->getJson("/api/v1/fleet/{$societe->id}/members")->assertOk();

    $indice = (string) $reponse->json('members.0.phone_hint');

    expect($indice)->toEndWith('8888')
        ->and($indice)->not->toContain('2250788')
        ->and($reponse->content())->not->toContain('+2250788888888');
});

it('refuse de s\'inviter soi-même', function (): void {
    [$gerant, $societe] = loueurAvecFlotte();

    expect(fn () => $this->membres->invite($societe, $gerant, $gerant->phone, CompanyRole::Admin))
        ->toThrow(DomainException::class);
});
