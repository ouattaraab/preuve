<?php

declare(strict_types=1);

use App\Enums\DocumentReviewStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UploadStatus;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\UploadSessionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0206 : envois différés avec reprise (CT-05).
 *
 * La promesse est celle d'un réseau qui lâche. Ce qui doit tenir n'est pas
 * l'envoi — il échouera — mais la possibilité de le REPRENDRE : sans position
 * exacte à renvoyer au client, la seule issue serait de recommencer quatre
 * mégaoctets depuis le début, en 3G, autant de fois que le réseau tombe.
 *
 * N'utilise pas RefreshDatabase : le dépôt d'un justificatif touche la chaîne
 * d'audit par le recalcul de fiabilité.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('upload_sessions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerEnvois();

    // Disques feints : le disque de travail reçoit des fichiers incomplets, le
    // bucket la pièce constituée.
    Storage::fake('local');
    Storage::fake('s3');
});
afterEach(fn () => nettoyerEnvois());

function nettoyerEnvois(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'upload_sessions', 'asset_documents', 'notifications',
        'asset_status_history', 'assets', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

/** Un JPEG minimal mais réel : le contenu est contrôlé à l'arrivée. */
function photoDeTest(int $repetitions = 40): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'.
        'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'.
        'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
    ).str_repeat("\x00", $repetitions);
}

function bienDuTesteur(): Asset
{
    $proprietaire = User::create(['phone' => '+2250701020304']);
    Sanctum::actingAs($proprietaire);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);
}

/** @return array{0: string, 1: string} identifiant de session et contenu */
function ouvrirEnvoi(Asset $bien, ?string $contenu = null, ?string $uuid = null): array
{
    $contenu ??= photoDeTest();
    $uuid ??= (string) Str::uuid();

    test()->postJson('/api/v1/uploads', [
        'uuid' => $uuid,
        'asset_id' => $bien->id,
        'doc_type' => 'photo',
        'filename' => 'photo.jpg',
        'byte_size' => strlen($contenu),
        'checksum' => hash('sha256', $contenu),
    ])->assertStatus(201);

    return [$uuid, $contenu];
}

function pousser(string $uuid, string $morceau, int $position): TestResponse
{
    return test()->call(
        'PATCH',
        '/api/v1/uploads/'.$uuid,
        [], [], [],
        [
            'HTTP_X_UPLOAD_OFFSET' => (string) $position,
            'CONTENT_TYPE' => 'application/octet-stream',
            // Sans quoi un refus de validation partirait en redirection : le
            // corps est brut, la requête n'est pas reconnue comme JSON.
            'HTTP_ACCEPT' => 'application/json',
        ],
        $morceau,
    );
}

it('constitue le justificatif à partir de morceaux successifs', function (): void {
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    $coupe = intdiv(strlen($contenu), 3);

    pousser($uuid, substr($contenu, 0, $coupe), 0)->assertOk();
    pousser($uuid, substr($contenu, $coupe, $coupe), $coupe)->assertOk();

    $reponse = pousser($uuid, substr($contenu, 2 * $coupe), 2 * $coupe)->assertOk();

    expect($reponse->json('status'))->toBe(UploadStatus::Completed->value);

    $document = AssetDocument::first();

    expect($document)->not->toBeNull()
        // L'empreinte figée au dépôt porte sur le fichier RECOMPOSÉ.
        ->and($document?->file_sha256)->toBe(hash('sha256', $contenu))
        // Une pièce arrivée en morceaux reste une pièce à examiner : elle ne
        // fait rien monter avant qu'un agent ne l'ait vue.
        ->and($document?->review_status)->toBe(DocumentReviewStatus::Pending);
});

it('rend la position réelle plutôt qu\'une erreur quand le client a perdu le fil', function (): void {
    // C'EST la story. Un client tué par le système ou dont la réponse s'est
    // perdue n'a pas besoin qu'on lui dise qu'il a tort : il a besoin de savoir
    // où reprendre. Sans cette valeur, il renverrait tout depuis le début.
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    $coupe = intdiv(strlen($contenu), 3);
    pousser($uuid, substr($contenu, 0, $coupe), 0)->assertOk();

    $reponse = pousser($uuid, substr($contenu, 0, $coupe), 0)->assertStatus(409);

    expect($reponse->json('received_bytes'))->toBe($coupe);

    // Et la reprise à la bonne position aboutit.
    pousser($uuid, substr($contenu, $coupe), $coupe)->assertOk();

    expect(AssetDocument::count())->toBe(1);
});

it('reprend après un redémarrage de l\'application', function (): void {
    // Le seul appel nécessaire au réveil : où en étais-je ?
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    $coupe = intdiv(strlen($contenu), 2);
    pousser($uuid, substr($contenu, 0, $coupe), 0)->assertOk();

    $reponse = test()->getJson('/api/v1/uploads/'.$uuid)->assertOk();

    expect($reponse->json('received_bytes'))->toBe($coupe)
        ->and($reponse->json('status'))->toBe(UploadStatus::Open->value);

    pousser($uuid, substr($contenu, $coupe), $coupe)->assertOk();

    expect(AssetDocument::count())->toBe(1);
});

it('ne dédouble pas un envoi rouvert après une réponse perdue', function (): void {
    // L'identité vient du client : sans cela, chaque réessai ouvrirait une
    // nouvelle session et le même document partirait cinq fois.
    $bien = bienDuTesteur();
    $contenu = photoDeTest();
    $uuid = (string) Str::uuid();

    ouvrirEnvoi($bien, $contenu, $uuid);
    ouvrirEnvoi($bien, $contenu, $uuid);
    ouvrirEnvoi($bien, $contenu, $uuid);

    expect(UploadSession::count())->toBe(1);
});

it('refuse un fichier dont l\'empreinte ne correspond pas', function (): void {
    // Un fichier recomposé sur un réseau instable peut être corrompu sans que
    // rien ne le signale. Un justificatif illisible qu'un agent accepterait
    // ferait tenir un niveau de fiabilité sur un document que personne n'a pu
    // lire : rien n'est accepté au bénéfice du doute.
    $bien = bienDuTesteur();
    $contenu = photoDeTest();
    $uuid = (string) Str::uuid();

    test()->postJson('/api/v1/uploads', [
        'uuid' => $uuid,
        'asset_id' => $bien->id,
        'doc_type' => 'photo',
        'filename' => 'photo.jpg',
        'byte_size' => strlen($contenu),
        // Empreinte d'un AUTRE contenu.
        'checksum' => hash('sha256', $contenu.'altéré'),
    ])->assertStatus(201);

    $reponse = pousser($uuid, $contenu, 0)->assertStatus(422);

    expect($reponse->json('status'))->toBe(UploadStatus::Failed->value)
        ->and($reponse->json('failure_reason'))->toContain('altéré')
        ->and(AssetDocument::count())->toBe(0);
});

it('refuse un contenu qui n\'est pas un justificatif, malgré son nom', function (): void {
    // Un envoi en morceaux échappe entièrement à la validation `mimes:` de
    // Laravel : aucune requête ne contient le fichier entier. Sans contrôle
    // final, déclarer « photo.jpg » et pousser n'importe quoi serait un dépôt
    // libre sur le bucket de la plateforme.
    $bien = bienDuTesteur();
    $contenu = "#!/bin/sh\necho compromis\n";
    $uuid = (string) Str::uuid();

    test()->postJson('/api/v1/uploads', [
        'uuid' => $uuid,
        'asset_id' => $bien->id,
        'doc_type' => 'photo',
        'filename' => 'photo.jpg',
        'byte_size' => strlen($contenu),
        'checksum' => hash('sha256', $contenu),
    ])->assertStatus(201);

    $reponse = pousser($uuid, $contenu, 0)->assertStatus(422);

    expect($reponse->json('status'))->toBe(UploadStatus::Failed->value)
        ->and(AssetDocument::count())->toBe(0);
});

it('n\'accepte pas plus d\'octets que la taille annoncée', function (): void {
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    pousser($uuid, $contenu.str_repeat('X', 100), 0)->assertStatus(422);

    expect(AssetDocument::count())->toBe(0);
});

it('refuse d\'ouvrir un envoi sur le bien d\'autrui', function (): void {
    // Un 404 plutôt qu'un 403 : confirmer l'existence d'un bien par son
    // identifiant interne donnerait un moyen de balayage.
    $bien = bienDuTesteur();

    $intrus = User::create(['phone' => '+2250709998877']);
    Sanctum::actingAs($intrus);

    test()->postJson('/api/v1/uploads', [
        'uuid' => (string) Str::uuid(),
        'asset_id' => $bien->id,
        'doc_type' => 'photo',
        'filename' => 'photo.jpg',
        'byte_size' => 100,
        'checksum' => hash('sha256', 'peu importe'),
    ])->assertStatus(404);
});

it('refuse de pousser des octets dans l\'envoi d\'un autre', function (): void {
    // Connaître un identifiant de session ne doit pas suffire à y écrire.
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    Sanctum::actingAs(User::create(['phone' => '+2250709998877']));

    pousser($uuid, $contenu, 0)->assertStatus(404);

    expect(AssetDocument::count())->toBe(0);
});

it('n\'exige aucun envoi pour que le bien existe', function (): void {
    // La promesse de fond : lier la création d'un bien à la fin d'un envoi
    // ferait dépendre sa protection de la qualité du réseau au moment où l'on
    // en a besoin.
    $bien = bienDuTesteur();
    ouvrirEnvoi($bien);

    expect($bien->fresh()?->active_flag)->toBe(1)
        ->and($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('efface le fichier de travail une fois la pièce constituée', function (): void {
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    pousser($uuid, $contenu, 0)->assertOk();

    Storage::disk('local')->assertMissing('uploads/'.$uuid.'.part');
});

it('purge les envois abandonnés et leurs fichiers', function (): void {
    // Chaque parcours interrompu en 3G laisse un fichier partiel sur le disque
    // de travail — le même que les sessions et le cache. Sans purge, un
    // mutualisé se remplit tout seul.
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    pousser($uuid, substr($contenu, 0, 20), 0)->assertOk();
    Storage::disk('local')->assertExists('uploads/'.$uuid.'.part');

    UploadSession::where('uuid', $uuid)->update(['expires_at' => now()->subDay()]);

    expect(app(UploadSessionService::class)->purgeExpired())->toBe(1)
        ->and(UploadSession::count())->toBe(0);

    Storage::disk('local')->assertMissing('uploads/'.$uuid.'.part');
});

it('refuse de reprendre un envoi expiré', function (): void {
    $bien = bienDuTesteur();
    [$uuid, $contenu] = ouvrirEnvoi($bien);

    UploadSession::where('uuid', $uuid)->update(['expires_at' => now()->subHour()]);

    pousser($uuid, $contenu, 0)->assertStatus(422);

    expect(AssetDocument::count())->toBe(0);
});

it('exige un compte pour envoyer', function (): void {
    // Toute écriture exige un compte authentifié (règle métier absolue n° 2).
    $bien = bienDuTesteur();
    $this->app['auth']->forgetGuards();

    test()->postJson('/api/v1/uploads', [
        'uuid' => (string) Str::uuid(),
        'asset_id' => $bien->id,
        'doc_type' => 'photo',
        'filename' => 'photo.jpg',
        'byte_size' => 100,
        'checksum' => hash('sha256', 'peu importe'),
    ])->assertStatus(401);
});
