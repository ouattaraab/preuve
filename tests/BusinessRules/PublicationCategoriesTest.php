<?php

declare(strict_types=1);

use App\Models\AssetCategory;
use App\Models\CategoryField;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publication du catalogue (décision D6).
 *
 * Sans catalogue, `POST /assets` refuse toute catégorie et le parcours central
 * du produit est injouable. Le seul écrivain était le jeu de démonstration, qui
 * refuse la production : une installation neuve ne pouvait rien enregistrer, et
 * cela ne se voyait qu'au premier essai.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_categories')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['assets', 'category_fields', 'asset_categories'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
});

it('publie un catalogue exploitable', function (): void {
    expect(Artisan::call('preuve:publish-categories'))->toBe(0);

    expect(AssetCategory::where('is_active', true)->count())->toBe(3);

    foreach (['moto', 'voiture', 'telephone'] as $cle) {
        $categorie = AssetCategory::where('key', $cle)->first();

        expect($categorie)->not->toBeNull()
            // Un seul champ canonique : c'est lui qui porte l'unicité de
            // l'enregistrement actif (règle métier absolue n° 3).
            ->and(CategoryField::where('asset_category_id', $categorie?->id)
                ->where('is_canonical_identifier', true)->count())->toBe(1);
    }
});

it('se relance sans rien dupliquer ni écraser', function (): void {
    Artisan::call('preuve:publish-categories');

    $categorie = AssetCategory::where('key', 'voiture')->first();
    $categorie?->forceFill(['name' => 'Automobile'])->save();

    Artisan::call('preuve:publish-categories');

    expect(AssetCategory::count())->toBe(3)
        ->and(CategoryField::count())->toBe(6)
        // Un renommage fait en exploitation n'est pas écrasé par une
        // republication.
        ->and(AssetCategory::where('key', 'voiture')->first()?->name)->toBe('Automobile');
});

it('s\'exécute en production, contrairement au jeu de démonstration', function (): void {
    // C'est tout son objet : le seeder refuse la production parce qu'il
    // fabriquerait de faux biens. Un catalogue, lui, n'atteste de rien.
    app()->detectEnvironment(fn (): string => 'production');

    expect(Artisan::call('preuve:publish-categories'))->toBe(0);

    app()->detectEnvironment(fn (): string => 'testing');
});
