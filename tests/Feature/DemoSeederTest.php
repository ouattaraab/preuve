<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\LookupService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('crée un bien par statut de vie', function (): void {
    // La démonstration aux loueurs doit pouvoir montrer chaque verdict tel
    // qu'un acheteur le verra.
    $this->seed(DemoSeeder::class);

    $statuts = Asset::pluck('life_status')->map(fn ($statut): string => $statut->value)->unique();

    foreach (LifeStatus::cases() as $statut) {
        expect($statuts)->toContain($statut->value);
    }
});

it('couvre les trois niveaux de fiabilité', function (): void {
    $this->seed(DemoSeeder::class);

    $niveaux = Asset::pluck('trust_level')->map(fn ($niveau): string => $niveau->value)->unique();

    foreach (TrustLevel::cases() as $niveau) {
        expect($niveaux)->toContain($niveau->value);
    }
});

it('n\'écrit rien dans la chaîne d\'audit', function (): void {
    // La chaîne se veut opposable : y insérer des actions qui n'ont jamais eu
    // lieu reviendrait à fabriquer de fausses preuves, indistinguables des
    // vraies si une base de démonstration était promue en production.
    $this->seed(DemoSeeder::class);

    expect(AuditLog::count())->toBe(0);
});

it('produit des comptes utilisables et un agent', function (): void {
    $this->seed(DemoSeeder::class);

    expect(User::where('phone', '+2250700000001')->sole()->hasVerifiedIdentity())->toBeTrue()
        ->and(User::where('phone', '+2250700000009')->sole()->role->hasBackOfficeAccess())->toBeTrue()
        ->and(User::where('phone', '+2250700000010')->sole()->isAdmin())->toBeTrue();
});

it('respecte l\'unicité active malgré une seconde exécution', function (): void {
    $this->seed(DemoSeeder::class);
    $premier = Asset::count();

    $this->seed(DemoSeeder::class);

    expect(Asset::count())->toBe($premier)
        ->and(Asset::whereNotNull('active_flag')->count())->toBe($premier);
});

it('rend chaque bien consultable publiquement', function (): void {
    $this->seed(DemoSeeder::class);

    $bien = Asset::where('life_status', LifeStatus::Stolen->value)->sole();

    // LA SAISIE BRUTE, telle qu'un acheteur la recopie de la carte grise. Ce
    // test interrogeait `identifier_normalized`, la forme déjà normalisée en
    // base : il ne pouvait donc pas voir un bien stocké sans normalisation,
    // c'est-à-dire le seul cas où la consultation échoue.
    $this->getJson('/api/v1/lookup/'.$bien->identifier_raw)
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('asset.life_status.label', 'Volé déclaré');
});

it('refuse de s\'exécuter en production', function (): void {
    // Les comptes et biens fictifs sont indistinguables des vrais une fois en
    // base.
    app()->detectEnvironment(fn (): string => 'production');

    // Appelé directement plutôt que par $this->seed() : ce dernier passe par
    // la console de test et masquerait l'exception derrière son propre
    // outillage.
    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class);
});

it('CRÉE DES BIENS QUE LA CONSULTATION TROUVE', function (): void {
    // Le seeder court-circuite `AssetRegistrationService` à dessein — un jeu de
    // démonstration n'a pas à écrire dans la chaîne d'audit. Mais rien ne le
    // dispense de NORMALISER : la consultation, elle, normalise toujours, et un
    // identifiant écrit tel quel donne un bien introuvable.
    //
    // LE DÉFAUT EST MUET. Le bien s'affiche dans le registre, dans l'inventaire
    // du détenteur, dans le tableau de flotte — partout sauf là où il sert.
    // Constaté en production le 05/08/2026 sur quatre véhicules dont
    // l'identifiant portait des tirets.
    $this->seed(DemoSeeder::class);
    $service = app(LookupService::class);

    foreach (Asset::where('active_flag', 1)->get() as $bien) {
        // La saisie BRUTE, telle qu'un acheteur la recopierait de la carte
        // grise — pas la forme déjà normalisée en base.
        $verdict = $service->lookup($bien->identifier_raw, '41.66.0.'.$bien->id);

        expect($verdict->verdict)->toBe(
            'known',
            "Le bien {$bien->identifier_raw} est en base mais introuvable en consultation."
        );
    }
});
