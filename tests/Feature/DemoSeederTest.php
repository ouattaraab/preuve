<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\User;
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

    $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)
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
