<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Registre des biens et annuaire des comptes, vus du back-office (lot 2).
 *
 * Deux règles gouvernent ces écrans, et elles vont plus loin que l'affichage.
 *
 * L'ANONYMAT NE CONNAÎT PAS D'EXCEPTION INTERNE (règle métier absolue n° 4).
 * Un back-office qui lèverait l'anonymat en ferait un outil de traque à
 * disposition de quiconque obtient un compte agent.
 *
 * SUSPENDRE UN COMPTE NE SUSPEND PAS SES BIENS. Couper la protection avec le
 * compte punirait les biens plutôt que le titulaire — et le premier lésé serait
 * l'acheteur de bonne foi.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('lookups')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerRegistreAdmin();
});
afterEach(fn () => nettoyerRegistreAdmin());

function nettoyerRegistreAdmin(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'lookups', 'asset_status_history', 'assets', 'personal_access_tokens', 'users'] as $t) {
        DB::statement("TRUNCATE TABLE {$t}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function agentRegistre(): User
{
    $agent = User::create(['phone' => '+2250700000900']);
    $agent->forceFill(['role' => UserRole::Admin, 'full_name' => 'Amara Kouassi'])->save();
    Sanctum::actingAs($agent);

    return $agent;
}

function detenteurRegistre(string $nom = 'Kouadio Yao'): User
{
    $u = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);
    $u->forceFill(['full_name' => $nom, 'kyc_status' => KycStatus::Verified->value])->save();

    return $u;
}

function bienDuRegistre(User $proprietaire, LifeStatus $statut = LifeStatus::Active): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_normalized' => 'REG'.strtoupper(bin2hex(random_bytes(7))),
        'identifier_raw' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Apsonic JX7'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut,
        'registered_at' => now()->subMonth(),
    ]);
}

it('ne dit rien du détenteur dans le registre des biens', function (): void {
    // LE contrôle de cet écran. L'anonymat est symétrique et sans exception
    // interne : un agent voit le bien, jamais qui le détient.
    $proprietaire = detenteurRegistre('Kouadio Yao');
    bienDuRegistre($proprietaire);
    agentRegistre();

    $reponse = test()->getJson('/api/v1/admin/assets')->assertOk();
    $corps = $reponse->getContent();

    expect($reponse->json('assets.0.label'))->toBe('Apsonic JX7')
        ->and($corps)->not->toContain('Kouadio Yao')
        ->and($corps)->not->toContain($proprietaire->phone)
        ->and($corps)->not->toContain('owner_user_id');
});

it('compte les consultations sans requête par ligne', function (): void {
    $bien = bienDuRegistre(detenteurRegistre());
    agentRegistre();

    foreach (range(1, 3) as $i) {
        DB::table('lookups')->insert([
            'identifier_normalized' => $bien->identifier_normalized,
            'ip_hash' => hash('sha256', (string) $i),
            'source' => 'app',
            'created_at' => now()->subDays(2),
        ]);
    }

    // Hors fenêtre : ne doit pas compter.
    DB::table('lookups')->insert([
        'identifier_normalized' => $bien->identifier_normalized,
        'ip_hash' => hash('sha256', 'vieux'),
        'source' => 'app',
        'created_at' => now()->subDays(40),
    ]);

    test()->getJson('/api/v1/admin/assets')
        ->assertOk()
        ->assertJsonPath('assets.0.lookups_30d', 3);
});

it('trouve un bien quelle que soit la façon dont on écrit son identifiant', function (): void {
    // Sans normalisation, « AA-123-BC » ne trouverait pas « AA123BC » et
    // l'agent conclurait à tort que le bien n'existe pas.
    $bien = bienDuRegistre(detenteurRegistre());
    agentRegistre();

    $saisie = substr($bien->identifier_normalized, 0, 4).'-'.substr($bien->identifier_normalized, 4, 3);

    test()->getJson('/api/v1/admin/assets?q='.urlencode($saisie))
        ->assertOk()
        ->assertJsonPath('assets.0.identifier', $bien->identifier_normalized);
});

it('masque les coordonnées, y compris pour un agent', function (): void {
    $proprietaire = detenteurRegistre('Awa Traoré');
    agentRegistre();

    $reponse = test()->getJson('/api/v1/admin/users')->assertOk();

    // Ciblé par identifiant : l'annuaire est trié par ordre d'inscription
    // décroissant, et se fier à une position rendrait le test fragile pour une
    // raison sans rapport avec son objet.
    $ligne = collect($reponse->json('users'))->firstWhere('id', $proprietaire->id);

    expect($reponse->getContent())->not->toContain($proprietaire->phone)
        ->and($ligne['contact'])->toEndWith(substr($proprietaire->phone, -2))
        ->and($ligne['contact'])->toContain('•')
        ->and($ligne['name'])->toBe('Awa Traoré');
});

it('ne laisse pas vérifier qu\'un numéro donné est inscrit', function (): void {
    // La recherche par numéro contournerait le masquage : il suffirait
    // d'essayer un numéro pour savoir si son titulaire a un compte.
    $proprietaire = detenteurRegistre('Awa Traoré');
    agentRegistre();

    test()->getJson('/api/v1/admin/users?q='.urlencode($proprietaire->phone))
        ->assertOk()
        ->assertJsonPath('pagination.total', 0);
});

it('suspend un compte sans jamais suspendre la protection de ses biens', function (): void {
    // L'INVARIANT DE CE LOT. Un bien déclaré volé reste déclaré volé : couper
    // la protection avec le compte punirait les biens plutôt que le titulaire,
    // et le premier lésé serait l'acheteur de bonne foi.
    $proprietaire = detenteurRegistre();
    $vole = bienDuRegistre($proprietaire, LifeStatus::Stolen);
    agentRegistre();

    test()->postJson("/api/v1/admin/users/{$proprietaire->id}/status", [
        'suspended' => true,
        'reason' => 'Dossiers de nuisance répétés.',
    ])->assertOk();

    expect($proprietaire->fresh()?->getAttribute('status'))->toBe('suspended');

    // La consultation publique, elle, ne change pas d'un iota.
    app('auth')->forgetGuards();

    test()->getJson('/api/v1/lookup/'.$vole->identifier_normalized)
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('asset.life_status.label', 'Volé déclaré');
});

it('coupe l\'accès d\'un compte suspendu sur-le-champ', function (): void {
    // Sans révocation des jetons, la suspension ne prendrait effet qu'à
    // l'expiration de la session en cours — c'est-à-dire trop tard.
    $cible = detenteurRegistre();
    $cible->createToken('mobile');

    agentRegistre();

    test()->postJson("/api/v1/admin/users/{$cible->id}/status", [
        'suspended' => true, 'reason' => 'Motif.',
    ])->assertOk();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $cible->id)->count())->toBe(0);
});

it('exige un motif écrit pour suspendre', function (): void {
    // Une suspension sans raison est incontestable, donc arbitraire — et le
    // titulaire ne saurait pas quoi corriger.
    $cible = detenteurRegistre();
    agentRegistre();

    test()->postJson("/api/v1/admin/users/{$cible->id}/status", ['suspended' => true])
        ->assertStatus(422);

    expect($cible->fresh()?->getAttribute('status'))->toBe('active');
});

it('journalise la suspension avec son motif, jamais le numéro', function (): void {
    $cible = detenteurRegistre();
    agentRegistre();

    test()->postJson("/api/v1/admin/users/{$cible->id}/status", [
        'suspended' => true, 'reason' => 'Dossiers de nuisance répétés.',
    ])->assertOk();

    $entree = AuditLog::where('action', 'admin.user_suspended')->first();

    expect($entree?->payload)->toBe(['reason' => 'Dossiers de nuisance répétés.'])
        // La chaîne est inaltérable et survivrait à tout effacement demandé :
        // aucune donnée personnelle n'y entre.
        ->and(json_encode($entree?->payload))->not->toContain($cible->phone);
});

it('empêche un administrateur de se suspendre lui-même', function (): void {
    // Il se fermerait la porte, et personne ne pourrait la rouvrir sur une
    // installation qui n'a qu'un compte.
    $admin = agentRegistre();

    test()->postJson("/api/v1/admin/users/{$admin->id}/status", [
        'suspended' => true, 'reason' => 'Erreur.',
    ])->assertStatus(422);

    expect($admin->fresh()?->getAttribute('status'))->toBe('active');
});

it('refuse ces écrans à qui n\'a pas accès au back-office', function (): void {
    Sanctum::actingAs(detenteurRegistre());

    test()->getJson('/api/v1/admin/assets')->assertStatus(403);
    test()->getJson('/api/v1/admin/users')->assertStatus(403);
});

/*
 * SÉPARATION DES POUVOIRS DANS LE BACK-OFFICE (faille d'élévation corrigée).
 *
 * La console s'ouvre aux agents ET aux administrateurs. Réécrire la coordonnée
 * d'un compte, ou le suspendre, sont des pouvoirs qui, appliqués à un compte du
 * back-office, permettent de prendre la place d'un administrateur (le code OTP
 * part vers la nouvelle adresse) ou d'enfermer la direction dehors. Ces gestes
 * sur un compte agent/admin se réservent donc aux administrateurs.
 */
function agentSimpleRegistre(): User
{
    $agent = User::create(['phone' => '+2250700000901']);
    $agent->forceFill(['role' => UserRole::Agent, 'full_name' => 'Agent Simple'])->save();
    Sanctum::actingAs($agent);

    return $agent;
}

it('INTERDIT à un agent de réécrire la coordonnée d\'un administrateur', function (): void {
    $admin = User::create(['phone' => '+2250700000010', 'email' => 'admin@preuve.ci']);
    $admin->forceFill(['role' => UserRole::Admin])->save();

    agentSimpleRegistre();

    test()->postJson("/api/v1/admin/users/{$admin->id}/contact", [
        'email' => 'pirate@attaquant.example',
        'reason' => 'tentative',
    ])->assertStatus(403);

    // L'adresse de l'administrateur n'a pas bougé : la prise de contrôle est fermée.
    expect($admin->fresh()?->email)->toBe('admin@preuve.ci');
});

it('INTERDIT à un agent de suspendre un autre membre du back-office', function (): void {
    $autreAgent = User::create(['phone' => '+2250700000902']);
    $autreAgent->forceFill(['role' => UserRole::Agent])->save();

    agentSimpleRegistre();

    test()->postJson("/api/v1/admin/users/{$autreAgent->id}/status", [
        'suspended' => true,
        'reason' => 'abus de pouvoir',
    ])->assertStatus(403);

    expect($autreAgent->fresh()?->getAttribute('status'))->not->toBe('suspended');
});

it('LAISSE un agent corriger la coordonnée d\'un utilisateur ordinaire', function (): void {
    // La borne ne ferme QUE les comptes du back-office : le travail de guichet
    // sur les comptes utilisateurs reste ouvert aux agents.
    $utilisateur = User::create(['phone' => '+2250701234567', 'email' => 'faute@exemple.ci']);

    agentSimpleRegistre();

    test()->postJson("/api/v1/admin/users/{$utilisateur->id}/contact", [
        'email' => 'corrige@exemple.ci',
        'reason' => 'adresse mal saisie',
    ])->assertOk();

    expect($utilisateur->fresh()?->email)->toBe('corrige@exemple.ci');
});

it('RÉVOQUE les sessions de la cible quand sa coordonnée change', function (): void {
    // Une correction de coordonnée sert souvent à reprendre la main : les
    // jetons émis avant ne doivent pas survivre au changement.
    $utilisateur = User::create(['phone' => '+2250701234599', 'email' => 'avant@exemple.ci']);
    $jeton = $utilisateur->createToken('mobile')->plainTextToken;

    agentRegistre(); // administrateur

    test()->postJson("/api/v1/admin/users/{$utilisateur->id}/contact", [
        'email' => 'apres@exemple.ci',
        'reason' => 'reprise en main',
    ])->assertOk();

    // Le jeton d'avant a été révoqué : plus aucune session active pour ce
    // compte. (On vérifie en base : `Sanctum::actingAs` court-circuiterait une
    // vérification par requête HTTP.)
    expect($utilisateur->tokens()->count())->toBe(0);
    unset($jeton);
});
