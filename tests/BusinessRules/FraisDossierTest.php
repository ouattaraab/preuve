<?php

declare(strict_types=1);

use App\Enums\ClaimDecision;
use App\Enums\EvidenceType;
use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Exceptions\FraisDossierImpayesException;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Payment;
use App\Models\User;
use App\Services\ClaimArbitrationService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Frais de dossier de réclamation (ST-0501, décision produit du 03/08/2026).
 *
 * Les frais conditionnent désormais le DÉPÔT — et le dépôt seulement. Ils
 * filtrent les dossiers de nuisance : contester la propriété d'autrui doit
 * coûter quelque chose. Mais ils filtrent aussi, mécaniquement, ceux qui n'ont
 * pas la somme — et la victime d'un enregistrement frauduleux est souvent
 * celle qui a le moins.
 *
 * La soupape est le réglage : à zéro, plus rien ne bloque. C'est ce qui empêche
 * le filtre anti-nuisance de devenir un filtre anti-pauvres, et c'est pourquoi
 * ce fichier l'éprouve autant que le blocage lui-même.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('claims')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerFrais();
    $this->arbitrage = app(ClaimArbitrationService::class);
});
afterEach(fn () => nettoyerFrais());

function nettoyerFrais(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'app_settings', 'payments', 'claim_evidences', 'claims',
        'notifications', 'asset_status_history', 'assets',
        'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function fixerFrais(int $montant): void
{
    app(SettingsRepository::class)->set(ClaimArbitrationService::FEE_SETTING, $montant);
    app(SettingsRepository::class)->fresh();
}

function partieFrais(): User
{
    $utilisateur = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);

    $utilisateur->forceFill([
        'kyc_status' => KycStatus::Verified->value,
        'kyc_verified_at' => now()->subMonths(3),
    ])->save();

    return $utilisateur;
}

function bienDeFrais(User $detenteur): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $detenteur->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_normalized' => 'FRAIS'.strtoupper(bin2hex(random_bytes(6))),
        'identifier_raw' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);
}

function dossierPret(User $reclamant): Claim
{
    $dossier = test()->arbitrage->open(bienDeFrais(partieFrais()), $reclamant);
    test()->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::OfficialNamedDoc);

    return $dossier->fresh() ?? $dossier;
}

function reglerLesFrais(Claim $dossier, User $reclamant): void
{
    Payment::create([
        'user_id' => $reclamant->id,
        'purpose' => PaymentPurpose::ClaimFee,
        'related_id' => $dossier->id,
        'provider' => 'paystack',
        'provider_ref' => 'ref-'.bin2hex(random_bytes(6)),
        'amount_fcfa' => app(ClaimArbitrationService::class)->feeAmount(),
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now(),
    ]);
}

it('laisse préparer le dossier sans rien payer', function (): void {
    // Les frais conditionnent le DÉPÔT, pas la préparation. Un réclamant doit
    // pouvoir monter son dossier et voir ce qu'il en coûtera avant de décider.
    fixerFrais(2000);

    $dossier = dossierPret(partieFrais());

    expect($dossier->id)->toBeGreaterThan(0)
        ->and(DB::table('claim_evidences')->where('claim_id', $dossier->id)->count())->toBe(1)
        ->and(DB::table('payments')->count())->toBe(0);
});

it('refuse le dépôt tant que les frais ne sont pas réglés', function (): void {
    fixerFrais(2000);

    $reclamant = partieFrais();
    $dossier = dossierPret($reclamant);

    expect(fn () => $this->arbitrage->submit($dossier))
        ->toThrow(FraisDossierImpayesException::class);

    // Rien n'a bougé : le bien n'est pas gelé, personne n'a été prévenu.
    expect($dossier->fresh()?->status->value)->toBe('draft')
        ->and(DB::table('notifications')->count())->toBe(0);
});

it('dit le montant dû plutôt que de se contenter d\'un refus', function (): void {
    // Un refus qui ne dit pas combien ne laisse que l'abandon — c'est-à-dire
    // une victime qui renonce à son seul recours.
    fixerFrais(2000);

    $reclamant = partieFrais();
    Sanctum::actingAs($reclamant);
    $dossier = dossierPret($reclamant);

    $refus = test()->postJson('/api/v1/claims/'.$dossier->id.'/submit')->assertStatus(402);

    expect($refus->json('fee.amount_fcfa'))->toBe(2000)
        ->and($refus->json('fee.paid'))->toBeFalse()
        ->and($refus->json('message'))->toContain('remboursés');
});

it('laisse déposer une fois les frais réglés', function (): void {
    fixerFrais(2000);

    $reclamant = partieFrais();
    $dossier = dossierPret($reclamant);
    reglerLesFrais($dossier, $reclamant);

    $depose = $this->arbitrage->submit($dossier->fresh() ?? $dossier);

    expect($depose->status->value)->not->toBe('draft');
});

it('ouvre le recours à tous quand les frais sont levés', function (): void {
    // LA SOUPAPE. À zéro, plus rien ne bloque : c'est ce qui empêche le filtre
    // anti-nuisance de devenir un filtre anti-pauvres, et cela se règle depuis
    // l'espace administrateur, sans livraison.
    fixerFrais(0);

    $reclamant = partieFrais();
    $dossier = dossierPret($reclamant);

    $depose = $this->arbitrage->submit($dossier);

    expect($depose->status->value)->not->toBe('draft')
        ->and(DB::table('payments')->count())->toBe(0)
        ->and($this->arbitrage->fee($depose)['required'])->toBeFalse();
});

it('ne confond pas la gratuité avec un réglage absent', function (): void {
    // Zéro est une valeur légitime. Un simple `?:` la confondrait avec « non
    // renseigné » et rétablirait des frais que l'administrateur vient
    // justement de lever.
    fixerFrais(0);

    expect($this->arbitrage->feeAmount())->toBe(0);
});

it('rembourse quand le réclamant avait raison', function (): void {
    fixerFrais(2000);

    $reclamant = partieFrais();
    $dossier = dossierPret($reclamant);
    reglerLesFrais($dossier, $reclamant);

    $depose = $this->arbitrage->submit($dossier->fresh() ?? $dossier);

    $this->arbitrage->decide(
        $depose->fresh() ?? $depose,
        agentDeFrais(),
        ClaimDecision::TransferToClaimant,
        'Facture nominative concordante.',
    );

    expect($this->arbitrage->fee($depose->fresh() ?? $depose)['refundable'])->toBeTrue();
});

function agentDeFrais(): User
{
    $agent = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);
    $agent->forceFill(['role' => UserRole::Agent])->save();

    return $agent;
}

it('permet à un administrateur de régler le montant, et le journalise', function (): void {
    // Relever les frais restreint l'accès au seul recours d'une victime : cela
    // ne doit pas pouvoir se faire sans trace, ni se découvrir après coup.
    $admin = User::create(['phone' => '+2250700000199']);
    $admin->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($admin);

    fixerFrais(2000);

    test()->putJson('/api/v1/admin/claim-fee', ['amount_fcfa' => 0])
        ->assertOk()
        ->assertJsonPath('blocking', false);

    expect(app(ClaimArbitrationService::class)->feeAmount())->toBe(0);

    $entree = AuditLog::where('action', 'admin.claim_fee_changed')->first();

    // Les DEUX montants : savoir qu'un changement a eu lieu ne suffit pas à
    // juger s'il a restreint ou ouvert l'accès au recours.
    expect($entree?->payload)->toBe(['from_fcfa' => 2000, 'to_fcfa' => 0]);
});

it('accepte zéro mais refuse un montant négatif', function (): void {
    $admin = User::create(['phone' => '+2250700000198']);
    $admin->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($admin);

    test()->putJson('/api/v1/admin/claim-fee', ['amount_fcfa' => 0])->assertOk();
    test()->putJson('/api/v1/admin/claim-fee', ['amount_fcfa' => -1])->assertStatus(422);
});

it('refuse le réglage à qui n\'est pas administrateur', function (): void {
    Sanctum::actingAs(partieFrais());

    test()->putJson('/api/v1/admin/claim-fee', ['amount_fcfa' => 0])->assertStatus(403);
});
