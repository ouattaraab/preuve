<?php

declare(strict_types=1);

use App\Enums\ClaimStatus;
use App\Enums\EvidenceType;
use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Notification;
use App\Models\User;
use App\Services\ClaimArbitrationService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0504 : le mis en cause dispose de 15 jours pour se défendre. C'est le
 * seul moment où il le peut, et son silence a une conséquence lourde — le
 * dossier sera instruit sur les seules pièces du réclamant.
 *
 * N'utilise pas RefreshDatabase : l'arbitrage écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    // Ces tests portent sur l'ARBITRAGE, pas sur les frais : le recours y est
    // gratuit, ce qui est un mode d'exploitation réel et non un artifice. La
    // barrière des frais a ses propres tests (FraisDossierTest).
    app(SettingsRepository::class)
        ->set(ClaimArbitrationService::FEE_SETTING, 0);

    if (! Schema::hasTable('claims')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerRelances();
    Storage::fake('s3');
    $this->arbitrage = app(ClaimArbitrationService::class);
});
afterEach(fn () => nettoyerRelances());

function nettoyerRelances(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'claim_evidences', 'claims',
        'asset_status_history', 'assets', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function personne(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    $utilisateur->forceFill([
        'kyc_status' => KycStatus::Verified->value,
        'kyc_verified_at' => now()->subMonth(),
    ])->save();

    return $utilisateur;
}

/** Dossier gelé, contradictoire ouvert, et le détenteur rendu avec. */
function dossierEnContradictoire(): array
{
    $detenteur = personne();

    $bien = Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $detenteur->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);

    $dossier = test()->arbitrage->open($bien, personne());
    test()->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::OfficialNamedDoc);
    $depose = test()->arbitrage->submit($dossier->fresh() ?? $dossier);

    // Notifications d'ouverture écartées : seules comptent les relances.
    Notification::query()->delete();

    return [$depose, $detenteur, $bien];
}

it('ne relance pas avant le septième jour', function (): void {
    dossierEnContradictoire();

    $this->travel(6)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    expect(Notification::count())->toBe(0);
});

it('relance au septième jour', function (): void {
    [, $detenteur] = dossierEnContradictoire();

    $this->travel(7)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    $relance = Notification::where('user_id', $detenteur->id)->sole();

    expect($relance->payload['days_left'] ?? null)->toBe(8)
        ->and($relance->body)->toContain('seules pièces disponibles');
});

it('ne répète pas la même relance à chaque passage', function (): void {
    // Une alerte répétée finit ignorée, et c'est celle-là qu'il ne faut pas
    // manquer.
    dossierEnContradictoire();

    $this->travel(7)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();
    $this->travel(2)->hours();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    expect(Notification::count())->toBe(1);
});

it('envoie un dernier rappel au treizième jour', function (): void {
    [, $detenteur] = dossierEnContradictoire();

    $this->travel(7)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    $this->travel(6)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    $relances = Notification::where('user_id', $detenteur->id)->orderBy('id')->get();

    expect($relances)->toHaveCount(2)
        ->and($relances[1]->title)->toContain('Dernier rappel')
        ->and($relances[1]->payload['days_left'] ?? null)->toBe(2);
});

it('n\'envoie que deux relances au total', function (): void {
    dossierEnContradictoire();

    foreach (range(1, 14) as $jour) {
        $this->travel(1)->day();
        $this->artisan('preuve:remind-contradictory')->assertSuccessful();
    }

    expect(Notification::where('title', 'like', '%appel%')->count())->toBeLessThanOrEqual(2);
});

it('rattrape la première relance si le passage a été manqué', function (): void {
    // Une panne de planificateur ne doit pas priver le mis en cause de son
    // seul avertissement.
    [, $detenteur] = dossierEnContradictoire();

    $this->travel(13)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    expect(Notification::where('user_id', $detenteur->id)->count())->toBe(1);
});

it('passe en instruction au terme du délai, sans dégeler le bien', function (): void {
    // Refermer le dossier faute de réponse donnerait raison au réclamant sans
    // examen — l'inverse de ce que le contradictoire protège.
    [$dossier, , $bien] = dossierEnContradictoire();

    $this->travel(16)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    expect($dossier->fresh()?->status)->toBe(ClaimStatus::UnderReview)
        ->and($bien->fresh()?->life_status)->toBe(LifeStatus::Disputed);
});

it('prévient les deux parties du passage en instruction', function (): void {
    [$dossier, $detenteur] = dossierEnContradictoire();

    $this->travel(16)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    expect(Notification::where('user_id', $detenteur->id)->count())->toBe(1)
        ->and(Notification::where('user_id', $dossier->claimant_user_id)->count())->toBe(1);
});

it('ne relance plus une fois le dossier en instruction', function (): void {
    dossierEnContradictoire();

    $this->travel(16)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();
    $compte = Notification::count();

    $this->travel(2)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    expect(Notification::count())->toBe($compte);
});

it('ne dit jamais qui conteste dans une relance', function (): void {
    [$dossier, $detenteur] = dossierEnContradictoire();
    $reclamant = User::findOrFail($dossier->claimant_user_id);
    $reclamant->forceFill(['full_name' => 'Awa Koné'])->save();

    $this->travel(7)->days();
    $this->artisan('preuve:remind-contradictory')->assertSuccessful();

    $relance = Notification::where('user_id', $detenteur->id)->sole();

    expect($relance->body)->not->toContain('Awa Koné')
        ->and($relance->body)->not->toContain($reclamant->phone);
});
