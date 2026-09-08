<?php

declare(strict_types=1);

use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Détail d'un bien : côté détenteur (`/assets/{id}/documents`) et côté
 * back-office (`/admin/assets/{id}`).
 *
 * CE QUE CES TESTS SURVEILLENT, c'est que le confort ouvert par ces deux écrans
 * ne perce pas la règle métier absolue n° 4. Un détenteur revoit SES pièces ; un
 * agent voit le bien, son histoire et ses justificatifs — jamais qui le détient,
 * ni qui a agi. Le back-office n'a pas d'exception interne à cette règle : il en
 * serait le premier outil de traque.
 *
 * N'utilise pas RefreshDatabase : le dépôt de pièces écrit dans la chaîne
 * d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_documents')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerDetail();
});
afterEach(fn () => nettoyerDetail());

function nettoyerDetail(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'asset_documents', 'asset_status_history',
        'assets', 'lookups', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function detenteurDetail(string $telephone = '+2250700000001', string $nom = 'Awa Koné'): User
{
    $utilisateur = User::create(['phone' => $telephone, 'full_name' => $nom]);

    return $utilisateur;
}

function bienDetail(User $detenteur, string $identifiant = '1M8GDM9AXKP042788'): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $detenteur->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);
}

function pieceDetail(Asset $bien, User $deposant): AssetDocument
{
    return AssetDocument::create([
        'asset_id' => $bien->id,
        'uploaded_by' => $deposant->id,
        'doc_type' => DocumentType::RegistrationCard,
        'file_ref' => 'documents/'.bin2hex(random_bytes(8)).'.enc',
        'file_sha256' => str_repeat('a', 64),
        'review_status' => DocumentReviewStatus::Pending,
    ]);
}

function agentDetail(): User
{
    $agent = User::create(['phone' => '+2250700000099', 'full_name' => 'Agent Traoré']);
    $agent->forceFill(['role' => UserRole::Agent])->save();

    return $agent;
}

it('rend au détenteur les pièces qu\'il a déposées', function (): void {
    // Sans cela, personne ne sait si sa carte grise est arrivée, ni si un agent
    // l'a acceptée : il la renverra, ou croira son bien documenté à tort.
    $moi = detenteurDetail();
    $bien = bienDetail($moi);
    pieceDetail($bien, $moi);

    Sanctum::actingAs($moi);

    $reponse = $this->getJson('/api/v1/assets/'.$bien->id.'/documents')->assertOk();

    expect($reponse->json('documents'))->toHaveCount(1)
        ->and($reponse->json('documents.0.doc_type'))->toBe('registration_card')
        ->and($reponse->json('documents.0.file_url'))
        ->toBe('/api/v1/assets/'.$bien->id.'/documents/'.$reponse->json('documents.0.id').'/file');
});

it('ne rend pas les pièces du bien d\'un autre', function (): void {
    $autre = detenteurDetail('+2250700000002');
    $bien = bienDetail($autre);
    pieceDetail($bien, $autre);

    Sanctum::actingAs(detenteurDetail());

    // 404 et non 403 : confirmer l'existence d'un bien par son identifiant
    // interne donnerait un moyen de balayage.
    $this->getJson('/api/v1/assets/'.$bien->id.'/documents')->assertStatus(404);
});

it('refuse la pièce d\'un bien qui n\'est pas le sien', function (): void {
    $autre = detenteurDetail('+2250700000002');
    $bienAutre = bienDetail($autre);
    $piece = pieceDetail($bienAutre, $autre);

    $moi = detenteurDetail();
    // Un second identifiant : l'unicité active interdit deux enregistrements
    // actifs sur le même numéro, et c'est précisément la règle métier n° 3.
    $mien = bienDetail($moi, 'JH4KA7561PC008269');

    Sanctum::actingAs($moi);

    // Le bien est le mien, la pièce ne l'est pas : un identifiant de document
    // deviné ne doit pas ouvrir la carte grise d'un inconnu.
    $this->get('/api/v1/assets/'.$mien->id.'/documents/'.$piece->id.'/file')->assertStatus(404);
});

it('ouvre le dossier complet d\'un bien à un agent', function (): void {
    $detenteur = detenteurDetail();
    $bien = bienDetail($detenteur);
    pieceDetail($bien, $detenteur);

    Sanctum::actingAs(agentDetail());

    $reponse = $this->getJson('/api/v1/admin/assets/'.$bien->id)->assertOk();

    expect($reponse->json('asset.identifier'))->toBe('1M8GDM9AXKP042788')
        ->and($reponse->json('asset.attributes.brand_model'))->toBe('Yamaha Crux')
        ->and($reponse->json('asset.is_active'))->toBeTrue()
        ->and($reponse->json('documents'))->toHaveCount(1)
        ->and($reponse->json('documents.0.file_url'))->toContain('/api/v1/admin/documents/');
});

it('NE DIT JAMAIS QUI DÉTIENT LE BIEN, même à un agent', function (): void {
    // LE test de cet écran. Un back-office qui lèverait l'anonymat en ferait un
    // outil de traque à disposition de quiconque obtient un compte agent — la
    // règle métier absolue n° 4 ne connaît pas d'exception interne.
    $detenteur = detenteurDetail('+2250700000001', 'Awa Koné');
    $bien = bienDetail($detenteur);

    Sanctum::actingAs(agentDetail());

    $contenu = $this->getJson('/api/v1/admin/assets/'.$bien->id)->assertOk()->content();

    expect($contenu)->not->toContain('Awa Koné')
        ->and($contenu)->not->toContain('+2250700000001')
        ->and($contenu)->not->toContain('owner_user_id')
        ->and($contenu)->not->toContain('uploaded_by');
});

it('dit par où passer pour lever l\'anonymat, plutôt que de se taire', function (): void {
    // Un champ absent sans explication se lit comme un défaut ; expliqué, il se
    // lit comme la protection qu'il est — et l'agent sait où aller.
    $bien = bienDetail(detenteurDetail());

    Sanctum::actingAs(agentDetail());

    $reponse = $this->getJson('/api/v1/admin/assets/'.$bien->id)->assertOk();

    expect($reponse->json('holder.disclosed'))->toBeFalse()
        ->and($reponse->json('holder.disclosure_path'))->toBe('/api/v1/admin/identity-disclosures')
        ->and($reponse->json('holder.notice'))->toContain('réquisition');
});

it('ferme le dossier d\'un bien à qui n\'est pas du back-office', function (): void {
    $bien = bienDetail(detenteurDetail());

    Sanctum::actingAs(detenteurDetail('+2250700000002'));

    $this->getJson('/api/v1/admin/assets/'.$bien->id)->assertStatus(403);
});
