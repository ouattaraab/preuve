<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Levée d'anonymat sur réquisition (Loi 2013-450).
 *
 * Elle existe parce que l'alternative est pire : une réquisition arrivera, et
 * sans chemin prévu l'exploitant y répondrait par une requête SQL directe —
 * sans fondement consigné, sans trace, sans registre.
 *
 * Ce fichier verrouille ce qui la rend défendable : réservée aux
 * administrateurs, adossée à un fondement structuré, limitée à une personne,
 * sans accès durable, et doublement tracée de façon ineffaçable.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('identity_disclosures')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerLevee();
});
afterEach(fn () => nettoyerLevee());

function nettoyerLevee(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'identity_disclosures', 'asset_status_history', 'assets',
        'personal_access_tokens', 'users'] as $t) {
        DB::statement("TRUNCATE TABLE {$t}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function sujetLevee(): User
{
    $u = User::create(['phone' => '+2250701020304']);
    $u->forceFill(['full_name' => 'Kouadio Yao', 'email' => 'k.yao@exemple.ci'])->save();

    return $u;
}

function requisitionValide(int $sujetId): array
{
    return [
        'user_id' => $sujetId,
        'authority' => "Tribunal de première instance d'Abidjan-Plateau",
        'reference' => 'REQ-2026-00412',
        'issued_on' => now()->subDay()->format('Y-m-d'),
        'purpose' => 'Enquête sur un réseau de recel de motocycles — identification du détenteur déclaré.',
    ];
}

function administrateurLevee(): User
{
    $a = User::create(['phone' => '+2250700000901']);
    $a->forceFill(['role' => UserRole::Admin, 'full_name' => 'Amara Kouassi'])->save();
    Sanctum::actingAs($a);

    return $a;
}

it('divulgue l\'identité contre une réquisition complète', function (): void {
    $sujet = sujetLevee();

    Asset::create([
        'public_ref' => 'PRV-ABCD1234',
        'owner_user_id' => $sujet->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Stolen,
        'registered_at' => now()->subMonth(),
    ]);

    administrateurLevee();

    $reponse = test()->postJson('/api/v1/admin/disclosures', requisitionValide($sujet->id))->assertOk();

    expect($reponse->json('identity.phone'))->toBe('+2250701020304')
        ->and($reponse->json('identity.full_name'))->toBe('Kouadio Yao')
        ->and($reponse->json('identity.assets.0.identifier'))->toBe('1M8GDM9AXKP042788')
        // Le numéro de pièce n'est conservé qu'en empreinte : le dire évite
        // qu'on le cherche, y compris à un magistrat.
        ->and($reponse->json('identity.id_number'))->toBeNull()
        ->and($reponse->json('identity.id_number_note'))->toContain('SHA-256');
});

it('refuse à un agent, qui instruit mais ne répond pas à un juge', function (): void {
    // Le rôle qui modère n'est pas celui qui lève l'anonymat.
    $sujet = sujetLevee();

    $agent = User::create(['phone' => '+2250700000902']);
    $agent->forceFill(['role' => UserRole::Agent])->save();
    Sanctum::actingAs($agent);

    test()->postJson('/api/v1/admin/disclosures', requisitionValide($sujet->id))->assertStatus(403);

    expect(DB::table('identity_disclosures')->count())->toBe(0);
});

it('exige un fondement structuré, pas un prétexte', function (): void {
    // Un champ libre unique se remplirait de « enquête » et ne prouverait rien.
    $sujet = sujetLevee();
    administrateurLevee();

    foreach (['authority', 'reference', 'issued_on', 'purpose'] as $champ) {
        $incomplete = requisitionValide($sujet->id);
        unset($incomplete[$champ]);

        test()->postJson('/api/v1/admin/disclosures', $incomplete)->assertStatus(422);
    }

    // Un objet trop court ne dit rien de l'enquête.
    test()->postJson('/api/v1/admin/disclosures', [
        ...requisitionValide($sujet->id), 'purpose' => 'enquête',
    ])->assertStatus(422);

    expect(DB::table('identity_disclosures')->count())->toBe(0);
});

it('n\'ouvre AUCUN accès durable', function (): void {
    // LE point. Une divulgation est un événement, pas un droit : la seconde
    // d'après, l'identité est de nouveau inaccessible. Un droit qui dure est un
    // droit qu'on oublie d'éteindre.
    $sujet = sujetLevee();
    administrateurLevee();

    test()->postJson('/api/v1/admin/disclosures', requisitionValide($sujet->id))->assertOk();

    // L'annuaire reste masqué, immédiatement après.
    $annuaire = test()->getJson('/api/v1/admin/users')->assertOk();

    expect($annuaire->getContent())->not->toContain('+2250701020304')
        ->and($annuaire->getContent())->not->toContain('k.yao@exemple.ci');
});

it('laisse une double trace ineffaçable', function (): void {
    $sujet = sujetLevee();
    $admin = administrateurLevee();

    test()->postJson('/api/v1/admin/disclosures', requisitionValide($sujet->id))->assertOk();

    $entree = AuditLog::where('action', 'admin.identity_disclosed')->first();

    expect($entree)->not->toBeNull()
        ->and($entree?->entity_id)->toBe($sujet->id)
        ->and($entree?->payload['reference'] ?? null)->toBe('REQ-2026-00412')
        // La chaîne consigne le FAIT et son fondement, jamais les données
        // divulguées : elle survivrait à tout exercice du droit à l'effacement.
        ->and(json_encode($entree?->payload))->not->toContain('+2250701020304');

    $ligne = DB::table('identity_disclosures')->first();

    expect($ligne?->subject_user_id)->toBe($sujet->id)
        ->and($ligne?->requested_by)->toBe($admin->id)
        ->and($ligne?->authority)->toContain('Abidjan-Plateau');
});

it('interdit toute réécriture du registre des levées', function (): void {
    // Un registre de divulgations qu'on peut réécrire ne prouve rien — et c'est
    // précisément le document qu'une autorité demanderait à voir.
    $sujet = sujetLevee();
    administrateurLevee();

    test()->postJson('/api/v1/admin/disclosures', requisitionValide($sujet->id))->assertOk();

    // Classe concrète et message attendu : `Throwable` seul accepterait
    // n'importe quel échec, y compris une erreur de syntaxe — le test
    // passerait sans rien prouver du déclencheur.
    expect(fn () => DB::table('identity_disclosures')->where('id', '>', 0)->update(['authority' => 'autre']))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('identity_disclosures')->where('id', '>', 0)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(DB::table('identity_disclosures')->count())->toBe(1);
});

it('rend le registre lisible sans nouvelle réquisition', function (): void {
    // Il ne contient aucune coordonnée : c'est le document de reddition de
    // comptes, et il ne servirait à rien s'il fallait une procédure pour le
    // consulter.
    $sujet = sujetLevee();
    administrateurLevee();

    test()->postJson('/api/v1/admin/disclosures', requisitionValide($sujet->id))->assertOk();

    $registre = test()->getJson('/api/v1/admin/disclosures')->assertOk();

    expect($registre->json('disclosures.0.reference'))->toBe('REQ-2026-00412')
        ->and($registre->getContent())->not->toContain('+2250701020304')
        ->and($registre->getContent())->not->toContain('Kouadio Yao');
});

it('refuse une réquisition datée du futur', function (): void {
    $sujet = sujetLevee();
    administrateurLevee();

    test()->postJson('/api/v1/admin/disclosures', [
        ...requisitionValide($sujet->id), 'issued_on' => now()->addWeek()->format('Y-m-d'),
    ])->assertStatus(422);
});
