<?php

declare(strict_types=1);

use App\Models\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function consultationDatee(string $quand): Lookup
{
    return Lookup::create([
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'found_asset_id' => null,
        'ip_hash' => str_repeat('a', 64),
        'user_id' => null,
        'source' => 'app',
        'created_at' => $quand,
    ]);
}

it('purge les consultations de plus de douze mois', function (): void {
    $ancienne = consultationDatee(now()->subMonths(13)->format('Y-m-d H:i:s'));
    $recente = consultationDatee(now()->subMonths(11)->format('Y-m-d H:i:s'));

    $this->artisan('preuve:purge-lookups')->assertSuccessful();

    expect(Lookup::find($ancienne->id))->toBeNull()
        ->and(Lookup::find($recente->id))->not->toBeNull();
});

it('accepte une ancienneté différente', function (): void {
    consultationDatee(now()->subMonths(4)->format('Y-m-d H:i:s'));

    $this->artisan('preuve:purge-lookups', ['--months' => 3])->assertSuccessful();

    expect(Lookup::count())->toBe(0);
});

it('supprime au-delà de la taille d\'un lot', function (): void {
    // La suppression procède par lots pour ne pas tenir un verrou trop
    // longtemps : la boucle doit bien aller jusqu'au bout.
    $lignes = [];

    foreach (range(1, 1200) as $index) {
        $lignes[] = [
            'identifier_normalized' => 'ID'.$index,
            'ip_hash' => str_repeat('b', 64),
            'source' => 'app',
            'created_at' => now()->subMonths(14)->format('Y-m-d H:i:s'),
        ];
    }

    Lookup::insert($lignes);

    $this->artisan('preuve:purge-lookups')->assertSuccessful();

    expect(Lookup::count())->toBe(0);
});

it('ne se plaint pas quand il n\'y a rien à purger', function (): void {
    consultationDatee(now()->subDay()->format('Y-m-d H:i:s'));

    $this->artisan('preuve:purge-lookups')->assertSuccessful();

    expect(Lookup::count())->toBe(1);
});
