<?php

declare(strict_types=1);

use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Services\CategoryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creerCategorieMoto(): AssetCategory
{
    $categorie = AssetCategory::create([
        'key' => 'moto',
        'name' => 'Moto',
        'icon' => '🛵',
        'position' => 1,
        'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id,
        'key' => 'chassis',
        'label' => 'N° de châssis',
        'type' => 'identifier',
        'is_required' => true,
        'is_canonical_identifier' => true,
        'position' => 1,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id,
        'key' => 'brand_model',
        'label' => 'Marque & modèle',
        'type' => 'text',
        'is_required' => true,
        'is_canonical_identifier' => false,
        'position' => 2,
    ]);

    return $categorie;
}

it('ne publie que les catégories actives', function (): void {
    creerCategorieMoto();
    AssetCategory::create([
        'key' => 'terrain', 'name' => 'Terrain (phase 2)', 'icon' => '📍',
        'position' => 4, 'is_active' => false,
    ]);

    $publiees = app(CategoryRegistry::class)->published();

    expect($publiees)->toHaveCount(1)
        ->and($publiees->first()->key)->toBe('moto');
});

it('change de version de configuration à chaque publication', function (): void {
    creerCategorieMoto();
    $registre = app(CategoryRegistry::class);

    $avant = $registre->version();
    $apres = $registre->publish();

    expect($apres)->not->toBe($avant);
});

it('construit les règles de validation à partir des champs déclarés', function (): void {
    creerCategorieMoto();

    $regles = app(CategoryRegistry::class)->validationRulesFor('moto');

    expect($regles)->toHaveKey('attributes.chassis')
        ->and($regles['attributes.chassis'])->toContain('required')
        ->and($regles)->toHaveKey('attributes.brand_model');
});

it('désigne exactement un champ comme identifiant canonique', function (): void {
    creerCategorieMoto();

    expect(app(CategoryRegistry::class)->canonicalFieldKey('moto'))->toBe('chassis');
});

it('refuse une catégorie sans identifiant canonique', function (): void {
    $categorie = AssetCategory::create([
        'key' => 'vide', 'name' => 'Sans identifiant', 'icon' => '❓',
        'position' => 9, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'note', 'label' => 'Note',
        'type' => 'text', 'is_required' => false, 'is_canonical_identifier' => false,
        'position' => 1,
    ]);

    expect(fn () => app(CategoryRegistry::class)->canonicalFieldKey('vide'))
        ->toThrow(RuntimeException::class, 'identifiant canonique');
});

it('sert la configuration publiée sans authentification', function (): void {
    creerCategorieMoto();
    app(CategoryRegistry::class)->publish();

    $reponse = $this->getJson('/api/v1/config/categories');

    $reponse->assertOk()
        ->assertJsonStructure(['version', 'categories' => [['key', 'name', 'icon', 'fields']]])
        ->assertJsonPath('categories.0.key', 'moto');
});

it('renvoie 304 quand la version détenue par l\'application est à jour', function (): void {
    creerCategorieMoto();
    $version = app(CategoryRegistry::class)->publish();

    $this->getJson('/api/v1/config/categories', ['If-None-Match' => '"'.$version.'"'])
        ->assertStatus(304);
});
