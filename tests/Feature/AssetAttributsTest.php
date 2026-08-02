<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * La colonne `attributes` porte les champs propres à la catégorie du bien
 * (décision D6 : catégories dynamiques, donc champs non figés dans le schéma).
 * Son nom entre en collision avec la propriété interne `Model::$attributes`
 * d'Eloquent — ce test verrouille le fait que la lecture publique traverse
 * bien le cast, faute de quoi tout code lisant les champs de catégorie
 * recevrait silencieusement le tableau brut des attributs du modèle.
 */
function bienAvecAttributs(array $attributs): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    $identifiant = 'ATTRS'.random_int(100000, 999999);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => 1,
        'attributes' => $attributs,
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Provisional,
        'registered_at' => now(),
    ]);
}

it('restitue les champs de catégorie et non le tableau interne du modèle', function (): void {
    $bien = bienAvecAttributs(['brand_model' => 'Yamaha Crux', 'year' => 2019]);

    $relu = Asset::findOrFail($bien->id);

    expect($relu->attributes)->toBe(['brand_model' => 'Yamaha Crux', 'year' => 2019])
        ->and($relu->getAttribute('attributes'))->toBe(['brand_model' => 'Yamaha Crux', 'year' => 2019])
        // Le tableau interne du modèle contiendrait aussi id, public_ref, etc. :
        // sa présence ici signalerait que la collision de noms l'emporte.
        ->and($relu->attributes)->not->toHaveKey('public_ref');
});

it('stocke les champs de catégorie en JSON dans la colonne, jamais en colonnes dédiées', function (): void {
    $bien = bienAvecAttributs(['brand_model' => 'Yamaha Crux']);

    $brut = DB::table('assets')->where('id', $bien->id)->value('attributes');

    expect($brut)->toBeString()
        ->and(json_decode((string) $brut, true))->toBe(['brand_model' => 'Yamaha Crux']);
});

it('accepte un bien sans aucun champ de catégorie', function (): void {
    $bien = bienAvecAttributs([]);

    expect(Asset::findOrFail($bien->id)->attributes)->toBe([]);
});
