<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function creerBien(string $identifiant, ?int $actif = 1): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => $actif,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Provisional,
        'registered_at' => now(),
    ]);
}

it('interdit deux enregistrements actifs pour le même identifiant', function (): void {
    creerBien('1M8GDM9AXKP042788');

    expect(fn () => creerBien('1M8GDM9AXKP042788'))
        ->toThrow(QueryException::class);
});

it('autorise plusieurs enregistrements archivés pour le même identifiant', function (): void {
    creerBien('1M8GDM9AXKP042788', null);
    creerBien('1M8GDM9AXKP042788', null);
    creerBien('1M8GDM9AXKP042788', 1);

    expect(Asset::where('identifier_normalized', '1M8GDM9AXKP042788')->count())->toBe(3)
        ->and(Asset::where('identifier_normalized', '1M8GDM9AXKP042788')
            ->whereNotNull('active_flag')->count())->toBe(1);
});

it('archive et recrée l\'actif dans une transaction unique sans jamais violer l\'unicité', function (): void {
    $ancien = creerBien('1M8GDM9AXKP042788');
    $nouveauProprietaire = User::create(['phone' => '+2250701020304']);

    DB::transaction(function () use ($ancien, $nouveauProprietaire): void {
        Asset::where('id', $ancien->id)->lockForUpdate()->first();
        Asset::where('id', $ancien->id)->update(['active_flag' => null]);

        Asset::create([
            'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
            'owner_user_id' => $nouveauProprietaire->id,
            'asset_category_key' => 'moto',
            'identifier_type' => 'vin',
            'identifier_raw' => '1M8GDM9AXKP042788',
            'identifier_normalized' => '1M8GDM9AXKP042788',
            'active_flag' => 1,
            'attributes' => ['brand_model' => 'Yamaha Crux'],
            'trust_level' => TrustLevel::Declared,
            'life_status' => LifeStatus::Active,
            'registered_at' => now(),
        ]);
    });

    $actifs = Asset::where('identifier_normalized', '1M8GDM9AXKP042788')
        ->whereNotNull('active_flag')->get();

    expect($actifs)->toHaveCount(1)
        ->and($actifs->first()->owner_user_id)->toBe($nouveauProprietaire->id);
});

it('n\'accepte que 1 ou NULL comme valeur de active_flag', function (): void {
    expect(fn () => creerBien('1M8GDM9AXKP042789', 2))
        ->toThrow(QueryException::class);
});

it('garantit l\'unicité de la référence publique', function (): void {
    $bien = creerBien('1M8GDM9AXKP042788');

    expect(fn () => Asset::create([
        'public_ref' => $bien->public_ref,
        'owner_user_id' => $bien->owner_user_id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => 'AUTRE123456789XX',
        'identifier_normalized' => 'AUTRE123456789XX',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Provisional,
        'registered_at' => now(),
    ]))->toThrow(QueryException::class);
});
