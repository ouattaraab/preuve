<?php

declare(strict_types=1);

use App\Jobs\ImportFleetChunk;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Models\Company;
use App\Models\FleetImport;
use App\Models\User;
use App\Services\CategoryRegistry;
use App\Services\FleetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Import de flotte au-delà de 200 lignes (ST-0702).
 *
 * Le fichier était tronqué à 200 lignes, le loueur devant découper et
 * réimporter lui-même. Ce n'était pas une limite de confort : chaque
 * enregistrement prend le verrou nommé de la chaîne d'audit, dont le plafond
 * mesuré est d'une quinzaine d'actions simultanées, et mille lignes traitées
 * d'un bloc rejetteraient les actions de tous les autres utilisateurs pendant
 * leur durée.
 *
 * Découpé en tranches par la file, l'import rend le verrou entre chacune.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('fleet_imports')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerImportFlotte();
    categorieImportFlotte();
    Storage::fake('local');
});
afterEach(fn () => nettoyerImportFlotte());

function nettoyerImportFlotte(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'fleet_imports', 'asset_status_history', 'assets',
        'category_fields', 'asset_categories', 'companies',
        'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieImportFlotte(): void
{
    $categorie = AssetCategory::create([
        'key' => 'voiture', 'name' => 'Voiture', 'icon' => '🚗', 'position' => 1, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'plate', 'label' => 'Plaque',
        'type' => 'identifier', 'is_required' => true, 'is_canonical_identifier' => true, 'position' => 1,
    ]);

    app(CategoryRegistry::class)->publish();
}

/** @return array{0: User, 1: Company} */
function loueurImport(): array
{
    $gerant = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);

    $societe = Company::create([
        'owner_user_id' => $gerant->id,
        'legal_name' => 'Loueur Test',
        'rccm_number' => 'CI-ABJ-'.random_int(100000, 999999),
        'status' => 'validated',
    ]);

    Sanctum::actingAs($gerant);

    return [$gerant, $societe];
}

/** Plaques ivoiriennes distinctes, au nouveau format AA123BC. */
function fichierDePlaques(int $combien): UploadedFile
{
    $lignes = "category,identifier,brand_model\n";

    foreach (range(1, $combien) as $i) {
        $lettres = chr(65 + intdiv($i, 26) % 26).chr(65 + $i % 26);
        $lignes .= sprintf("voiture,%s%03d%sZ,Toyota\n", $lettres, $i % 1000, chr(65 + $i % 26));
    }

    return UploadedFile::fake()->createWithContent('flotte.csv', $lignes);
}

it('traite un petit fichier dans la requête, sans identifiant de suivi', function (): void {
    // Rendre un identifiant à interroger pour douze véhicules serait une
    // régression d'usage déguisée en progrès technique.
    [, $societe] = loueurImport();

    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(5)])
        ->assertOk()
        ->assertJsonPath('report.imported', 5);

    expect(FleetImport::count())->toBe(0);
});

it('met un gros fichier en file, découpé en tranches', function (): void {
    // LE point. Un unique travail de mille lignes tiendrait le travailleur et
    // disputerait le verrou d'audit pendant plusieurs minutes.
    Queue::fake();

    [, $societe] = loueurImport();

    $reponse = test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])
        ->assertStatus(202);

    $import = FleetImport::first();

    expect($import?->total_rows)->toBe(250)
        ->and($import?->status)->toBe('pending')
        ->and($reponse->json('import.id'))->toBe($import?->id);

    // Une seule tranche est dépêchée : les suivantes s'enchaînent d'elles-mêmes.
    Queue::assertPushed(ImportFleetChunk::class, 1);
    Queue::assertPushed(ImportFleetChunk::class, fn (ImportFleetChunk $job): bool => $job->offset === 0
        && $job->queue === 'fleet-imports');
});

it('importe réellement toutes les lignes, tranche après tranche', function (): void {
    [, $societe] = loueurImport();

    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])
        ->assertStatus(202);

    // Le pilote `sync` enchaîne les tranches : l'import se déroule en entier.
    $import = FleetImport::first();

    expect($import?->status)->toBe('completed')
        ->and($import?->processed_rows)->toBe(250)
        ->and(Asset::where('company_id', $societe->id)->count())->toBe($import?->imported);
});

it('efface le fichier dès l\'import terminé', function (): void {
    // Il porte l'inventaire complet d'un parc : il ne survit pas à l'import.
    [, $societe] = loueurImport();

    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])
        ->assertStatus(202);

    $import = FleetImport::first();

    expect($import?->storage_ref)->toBeNull()
        ->and(Storage::disk('local')->allFiles('fleet-imports'))->toBeEmpty();
});

it('ne duplique rien quand on réimporte le même fichier', function (): void {
    // Un import interrompu se reprend sans rien dupliquer : c'est aussi ce qui
    // rend une tranche rejouable après incident.
    [, $societe] = loueurImport();

    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])->assertStatus(202);
    $apresPremier = Asset::where('company_id', $societe->id)->count();

    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])->assertStatus(202);

    expect(Asset::where('company_id', $societe->id)->count())->toBe($apresPremier);

    $second = FleetImport::latest('id')->first();

    // Tout est compté comme ignoré, rien comme erreur.
    expect($second?->imported)->toBe(0)
        ->and($second?->skipped)->toBe($apresPremier);
});

it('refuse un fichier hors de toute proportion', function (): void {
    // Un fichier sans borne est un problème en soi : cent mille véhicules
    // relèvent d'une reprise de données, pas d'un formulaire.
    [$gerant, $societe] = loueurImport();

    expect(fn () => app(FleetService::class)->queueImport(
        $societe,
        $gerant,
        fichierDePlaques(FleetService::MAX_QUEUED_ROWS + 1),
    ))->toThrow(DomainException::class, 'reprise de données');
})->skip('génère 10 001 lignes : trop lent pour la suite ordinaire.');

it('laisse le loueur suivre son import', function (): void {
    [, $societe] = loueurImport();

    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])->assertStatus(202);

    $import = FleetImport::first();

    test()->getJson("/api/v1/fleet/{$societe->id}/imports/{$import?->id}")
        ->assertOk()
        ->assertJsonPath('import.status', 'completed')
        ->assertJsonPath('import.total_rows', 250);
});

it('ne laisse pas lire l\'import d\'un autre loueur', function (): void {
    // Un identifiant d'import ne doit pas ouvrir l'inventaire d'un tiers.
    [, $societe] = loueurImport();
    test()->post("/api/v1/fleet/{$societe->id}/import", ['file' => fichierDePlaques(250)])->assertStatus(202);
    $import = FleetImport::first();

    [, $autre] = loueurImport();

    test()->getJson("/api/v1/fleet/{$autre->id}/imports/{$import?->id}")->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| L'import depuis le web (EP-07)
|--------------------------------------------------------------------------
|
| L'application mobile le promet mot pour mot : « L'import d'un parc entier se
| fait depuis un fichier, sur le web ». Cette page n'existait pas, alors que le
| serveur savait importer depuis le 3 août.
|
| C'est bien sur le web que ça se passe : un fichier de parc vit sur
| l'ordinateur d'une agence, pas dans le téléphone du gérant.
*/

it('MONTRE LA PAGE D\'IMPORT, avec ce qu\'elle attend', function (): void {
    // La page doit dire le format attendu AVANT l'envoi : un fichier refusé
    // après coup fait recommencer, et un loueur ne devine pas trois noms de
    // colonnes.
    [$gerant, $societe] = loueurImport();

    test()->actingAs($gerant)->get('/flotte/import')
        ->assertOk()
        ->assertSee('Importer un parc')
        ->assertSee($societe->legal_name)
        ->assertSee('category,identifier,brand_model')
        // Une seule société : on ne fait pas cocher l'unique option disponible.
        ->assertDontSee('<select', false)
        // Et ce qui rassure avant de cliquer : réimporter ne casse rien.
        ->assertSee('déjà connu');
});

it('IMPORTE UN PARC DEPUIS LE WEB', function (): void {
    [$gerant, $societe] = loueurImport();

    test()->actingAs($gerant)
        ->post('/flotte/import', [
            'company' => $societe->id,
            'file' => fichierDePlaques(5),
        ])
        ->assertRedirect();

    expect(Asset::where('company_id', $societe->id)->count())->toBe(5);
});

it('RENVOIE VERS UN SUIVI au-delà du seuil, sans bloquer la page', function (): void {
    Queue::fake();
    [$gerant, $societe] = loueurImport();

    test()->actingAs($gerant)
        ->post('/flotte/import', [
            'company' => $societe->id,
            'file' => fichierDePlaques(FleetService::MAX_ROWS + 5),
        ])
        ->assertRedirect(route('fleet.import'))
        ->assertSessionHas('suivi');

    expect(FleetImport::where('company_id', $societe->id)->count())->toBe(1);
});

it('UNE SESSION DE LOUEUR N\'OUVRE PAS LE BACK-OFFICE', function (): void {
    // LE test de cette page. Les deux espaces partagent le gardien de session
    // de Laravel ; si le contrôle de rôle du back-office ne tenait pas, un
    // client verrait la piste d'audit et la modération d'identité.
    [$gerant] = loueurImport();

    test()->actingAs($gerant)->get('/flotte/import')->assertOk();

    // 403, franchement, et non une redirection : la porte est fermée, pas
    // déplacée.
    test()->actingAs($gerant)->get('/admin/moderation')->assertForbidden();
    test()->actingAs($gerant)->get('/admin/audit')->assertForbidden();
});

it('N\'IMPORTE PAS DANS LE PARC D\'UN AUTRE', function (): void {
    [$gerant] = loueurImport();
    [, $autre] = loueurImport();

    test()->actingAs($gerant)
        ->post('/flotte/import', ['company' => $autre->id, 'file' => fichierDePlaques(3)])
        // 404 et non 403 : distinguer « n'existe pas » de « pas à vous »
        // permettrait de dénombrer les sociétés du registre, une par une.
        ->assertNotFound();

    expect(Asset::where('company_id', $autre->id)->count())->toBe(0);
});

it('NE MONTRE PAS LE SUIVI D\'UN AUTRE', function (): void {
    Queue::fake();
    [$gerant] = loueurImport();
    [$autreGerant, $autre] = loueurImport();

    test()->actingAs($autreGerant)->post('/flotte/import', [
        'company' => $autre->id,
        'file' => fichierDePlaques(FleetService::MAX_ROWS + 2),
    ]);

    $import = FleetImport::where('company_id', $autre->id)->sole();

    test()->actingAs($gerant)->get('/flotte/import/'.$import->id)->assertNotFound();
    test()->actingAs($autreGerant)->get('/flotte/import/'.$import->id)->assertOk();
});

it('FERME LA PORTE À QUI NE DIRIGE AUCUNE FLOTTE', function (): void {
    $quidam = User::create(['phone' => '+22505'.random_int(10000000, 99999999)]);

    test()->actingAs($quidam)->get('/flotte/import')->assertRedirect(route('fleet.login'));
    test()->get('/flotte/import')->assertRedirect(route('fleet.login'));
});

it('NOMME LES LIGNES QUI NE PASSENT PAS, plutôt que de tout rejeter', function (): void {
    // Un fichier de quarante véhicules dont trois sont mal saisis ne doit pas
    // échouer en entier : les trente-sept bons entrent, et les trois autres
    // sont nommés pour être corrigés. Tout rejeter ferait recommencer un
    // fichier presque juste.
    [$gerant, $societe] = loueurImport();

    $melange = UploadedFile::fake()->createWithContent(
        'flotte.csv',
        "category,identifier,brand_model\nvoiture,AA123BZ,Toyota\nvoiture,,Peugeot\n",
    );

    test()->actingAs($gerant)
        ->post('/flotte/import', ['company' => $societe->id, 'file' => $melange])
        ->assertRedirect()
        ->assertSessionHas('rapport', fn (array $r): bool => $r['imported'] === 1 && $r['failed'] === 1);
});

it('DIT POURQUOI un fichier entier est refusé, au lieu de le rejeter en silence', function (): void {
    // « Ça n'a pas marché » fait réessayer à l'identique. Le motif — ici, la
    // ligne d'en-tête attendue — permet de corriger le fichier.
    [$gerant, $societe] = loueurImport();

    $vide = UploadedFile::fake()->createWithContent('flotte.csv', "rien,du,tout\n");

    test()->actingAs($gerant)
        ->post('/flotte/import', ['company' => $societe->id, 'file' => $vide])
        ->assertRedirect()
        ->assertSessionHasErrors('file');
});

it('la page de connexion ne dit pas si le numéro est connu', function (): void {
    // Toute différence observable ferait de cette page un annuaire des loueurs.
    [$gerant] = loueurImport();

    $connu = test()->post('/flotte/connexion/code', ['phone' => $gerant->phone]);
    $inconnu = test()->post('/flotte/connexion/code', ['phone' => '+2250500000000']);

    expect($connu->getStatusCode())->toBe($inconnu->getStatusCode())
        ->and(session('message'))->toContain('Si ce compte peut recevoir un code');
});
