<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Revue d'un dossier d'identité par un agent (ST-0103).
 *
 * LE NOM DÉCLARÉ Y FIGURE, ET CE N'EST PAS UNE ENTORSE À LA RÈGLE N° 4. Cette
 * règle protège le lien « ce bien ↔ cette personne » : le consultant ne voit
 * pas le déclarant, le propriétaire ne voit pas le consultant, le rapport payant
 * ne nomme personne. Un dossier d'identité ne porte aucun bien.
 *
 * SANS LE NOM, LA REVUE EST IMPOSSIBLE : l'agent doit comparer ce que la
 * personne DÉCLARE avec ce que la pièce DIT. Et il voit déjà ce nom — il est
 * imprimé sur la pièce qu'on lui affiche.
 *
 * CE QUE CES TESTS SURVEILLENT, c'est la frontière : le dossier d'identité ne
 * doit jamais laisser remonter les BIENS de la personne. C'est là, et là
 * seulement, que la règle n° 4 s'applique.
 *
 * N'utilise pas RefreshDatabase : la décision écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('kyc_submissions')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerRevueIdentite();
});
afterEach(fn () => nettoyerRevueIdentite());

function nettoyerRevueIdentite(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'kyc_submissions', 'asset_status_history',
        'assets', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function deposantIdentite(): User
{
    return User::create(['phone' => '+2250700000055', 'full_name' => 'Awa Koné']);
}

function dossierIdentite(User $personne): KycSubmission
{
    return KycSubmission::create([
        'user_id' => $personne->id,
        'status' => 'pending',
        'id_front_ref' => 'kyc/front.enc',
        'id_back_ref' => 'kyc/back.enc',
        'selfie_ref' => 'kyc/selfie.enc',
        'id_front_sha256' => str_repeat('a', 64),
        'id_back_sha256' => str_repeat('b', 64),
        'selfie_sha256' => str_repeat('c', 64),
    ]);
}

function agentIdentite(): User
{
    $agent = User::create(['phone' => '+2250700000098', 'full_name' => 'Agent Traoré']);
    $agent->forceFill(['role' => UserRole::Agent])->save();

    return $agent;
}

it('rend le nom déclaré, sans quoi la revue ne vérifie rien', function (): void {
    // L'agent compare ce que la personne DÉCLARE avec ce que la pièce DIT. Sans
    // le nom, il ne peut que constater qu'une image existe.
    $personne = deposantIdentite();
    dossierIdentite($personne);

    Sanctum::actingAs(agentIdentite());

    $reponse = $this->getJson('/api/v1/admin/kyc')->assertOk();

    expect($reponse->json('submissions.0.holder.full_name'))->toBe('Awa Koné')
        ->and($reponse->json('submissions.0.holder.account_created_at'))->not->toBeNull();
});

it('donne les trois images à comparer', function (): void {
    dossierIdentite(deposantIdentite());

    Sanctum::actingAs(agentIdentite());

    $images = $this->getJson('/api/v1/admin/kyc')->assertOk()->json('submissions.0.images');

    expect($images['id_front'])->toContain('/file/front')
        ->and($images['id_back'])->toContain('/file/back')
        ->and($images['selfie'])->toContain('/file/selfie');
});

it('NE LAISSE JAMAIS REMONTER LES BIENS DE LA PERSONNE', function (): void {
    // LA FRONTIÈRE. Le dossier d'identité peut nommer quelqu'un ; il ne doit
    // jamais dire ce qu'il possède. C'est ce lien-là que la règle métier
    // absolue n° 4 protège, et le rendre depuis cet écran ferait du back-office
    // exactement l'outil de traque qu'elle interdit.
    $personne = deposantIdentite();
    dossierIdentite($personne);

    Asset::create([
        'public_ref' => 'PRV-SECRET1',
        'owner_user_id' => $personne->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now(),
    ]);

    Sanctum::actingAs(agentIdentite());

    $contenu = $this->getJson('/api/v1/admin/kyc')->assertOk()->content();

    expect($contenu)->not->toContain('PRV-SECRET1')
        ->and($contenu)->not->toContain('1M8GDM9AXKP042788')
        ->and($contenu)->not->toContain('asset');
});

it('ne rend ni téléphone ni adresse : ils n\'aident pas à décider', function (): void {
    // Minimisation (Loi 2013-450) : le nom sert à comparer avec la pièce, un
    // numéro de téléphone ne compare rien. Les afficher ferait du back-office
    // un annuaire.
    $personne = deposantIdentite();
    $personne->forceFill(['email' => 'awa@exemple.ci'])->save();
    dossierIdentite($personne);

    Sanctum::actingAs(agentIdentite());

    $contenu = $this->getJson('/api/v1/admin/kyc')->assertOk()->content();

    expect($contenu)->not->toContain('+2250700000055')
        ->and($contenu)->not->toContain('awa@exemple.ci');
});

it('ne rend jamais le numéro de la pièce, même à un agent', function (): void {
    // Il n'est conservé qu'en empreinte : il n'est restituable à personne, y
    // compris sur réquisition. La confidentialité publiée le promet.
    $personne = deposantIdentite();
    $personne->forceFill(['kyc_id_number_hash' => str_repeat('d', 64)])->save();
    dossierIdentite($personne);

    Sanctum::actingAs(agentIdentite());

    $contenu = $this->getJson('/api/v1/admin/kyc')->assertOk()->content();

    expect($contenu)->not->toContain('kyc_id_number_hash')
        ->and($contenu)->not->toContain(str_repeat('d', 64));
});

it('ferme la file d\'identités à qui n\'est pas du back-office', function (): void {
    dossierIdentite(deposantIdentite());

    Sanctum::actingAs(User::create(['phone' => '+2250700000077']));

    $this->getJson('/api/v1/admin/kyc')->assertStatus(403);
});
