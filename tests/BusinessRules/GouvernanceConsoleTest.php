<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AuditLog;
use App\Models\CategoryField;
use App\Models\User;
use App\Services\AuditChain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Gouvernance de la console : piste d'audit, catalogue, habilitations (lot 3).
 *
 * Ces trois écrans ont en commun de ne pas instruire des dossiers mais de
 * configurer la plateforme, et c'est pour cela qu'ils sont fermés aux agents.
 *
 * LA PISTE D'AUDIT N'OFFRE AUCUNE ÉCRITURE. Pas seulement parce que les
 * déclencheurs de base l'interdisent : un bouton qui échouerait toujours
 * enseignerait qu'une modification est concevable.
 *
 * UNE CATÉGORIE SE DÉSACTIVE, ELLE NE SE SUPPRIME PAS. Des biens y sont
 * rattachés ; les faire disparaître avec elle priverait leurs propriétaires de
 * la fiche qui les protège.
 *
 * PROMOUVOIR FABRIQUE LES COMPTES QUI LÈVENT L'ANONYMAT. C'est l'opération la
 * plus dangereuse de la console : réservée aux administrateurs, interdite sur
 * soi-même, et journalisée avec l'ancien ET le nouveau rôle.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('audit_log')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerGouvernance();
});
afterEach(fn () => nettoyerGouvernance());

function nettoyerGouvernance(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'assets', 'category_fields', 'asset_categories',
        'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function administrateurGouvernance(): User
{
    $compte = User::create(['phone' => '+2250700001200', 'email' => 'admin@preuve.test']);
    $compte->forceFill(['role' => UserRole::Admin, 'full_name' => 'Awa Traoré'])->save();
    Sanctum::actingAs($compte);

    return $compte;
}

function agentGouvernance(): User
{
    $compte = User::create(['phone' => '+2250700001201', 'email' => 'agent@preuve.test']);
    $compte->forceFill(['role' => UserRole::Agent, 'full_name' => 'Kone Ibrahim'])->save();

    return $compte;
}

function categorieGouvernance(string $cle = 'voiture'): AssetCategory
{
    $categorie = AssetCategory::create([
        'key' => $cle,
        'name' => 'Voiture',
        'icon' => '🚗',
        'position' => 1,
        'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id,
        'key' => 'vin',
        'label' => 'Numéro de châssis',
        'type' => 'identifier',
        'is_required' => true,
        'is_canonical_identifier' => true,
        'position' => 1,
    ]);

    return $categorie;
}

/* -------------------------------------------------------------------- */
/* Piste d'audit */
/* -------------------------------------------------------------------- */

it("n'expose aucune route d'écriture sur la piste d'audit", function (): void {
    administrateurGouvernance();

    // Ce n'est pas un contrôle d'autorisation : la route n'existe pas. Une
    // console qui proposerait le geste, même refusé, laisserait croire qu'un
    // journal d'audit se corrige.
    foreach ([['post', ''], ['put', '/1'], ['patch', '/1'], ['delete', '/1']] as [$verbe, $suffixe]) {
        $reponse = $this->{'json'}(strtoupper($verbe), '/api/v1/admin/audit-trail'.$suffixe, []);

        expect($reponse->getStatusCode())->toBeIn([404, 405]);
    }
});

it("ferme la piste d'audit aux agents", function (): void {
    agentGouvernance();
    Sanctum::actingAs(User::where('role', UserRole::Agent->value)->firstOrFail());

    // Le journal dit qui a fait quoi, y compris les autres agents. Instruire un
    // dossier ne suppose pas de savoir ce qu'un collègue a décidé hier.
    $this->getJson('/api/v1/admin/audit-trail')->assertStatus(403);
    $this->getJson('/api/v1/admin/audit-trail/export')->assertStatus(403);
});

it('rend la piste d\'audit avec son empreinte de chaînage', function (): void {
    $admin = administrateurGouvernance();

    app(AuditChain::class)->append(ActorType::Agent, $admin->id, 'test.action', 'asset', 7, ['k' => 'v']);

    $reponse = $this->getJson('/api/v1/admin/audit-trail')->assertOk();

    $entree = $reponse->json('entries.0');

    expect($entree['action'])->toBe('test.action')
        ->and($entree['entity_id'])->toBe(7)
        // Sans l'empreinte, l'écran ne serait qu'un tableau : personne ne
        // pourrait rapprocher une ligne d'un ancrage publié à l'extérieur.
        ->and($entree['chain_hash'])->toBe(AuditLog::query()->orderByDesc('id')->firstOrFail()->chain_hash);
});

it("diffuse l'export CSV avec le BOM qui le rend lisible", function (): void {
    $admin = administrateurGouvernance();

    app(AuditChain::class)->append(ActorType::Agent, $admin->id, 'asset.registered', 'asset', 3, ['statut' => 'Volé déclaré']);

    $reponse = $this->get('/api/v1/admin/audit-trail/export')->assertOk();

    $corps = $reponse->streamedContent();

    // Sans le BOM, Excel rend « Volé déclaré » en « VolÃ© dÃ©clarÃ© » et
    // l'export devient inexploitable pour qui le reçoit.
    expect(str_starts_with($corps, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($corps)->toContain('asset.registered')
        ->and($corps)->toContain('Volé déclaré');
});

/* -------------------------------------------------------------------- */
/* Catalogue des catégories */
/* -------------------------------------------------------------------- */

it('désactive une catégorie sans supprimer ni détacher les biens', function (): void {
    $admin = administrateurGouvernance();
    $categorie = categorieGouvernance();

    $bien = Asset::create([
        'public_ref' => 'PRV-GOUV-01',
        'owner_user_id' => $admin->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'registered_at' => now(),
    ]);

    $this->postJson("/api/v1/admin/categories/{$categorie->id}/active", ['active' => false])->assertOk();

    // La catégorie survit à sa désactivation, et le bien avec elle : un bien
    // dont la catégorie a disparu deviendrait inaffichable, et son propriétaire
    // perdrait la fiche qui le protège.
    expect(AssetCategory::find($categorie->id))->not->toBeNull()
        ->and(AssetCategory::find($categorie->id)?->is_active)->toBeFalsy()
        ->and(Asset::find($bien->id)?->asset_category_key)->toBe('voiture')
        ->and(Asset::find($bien->id)?->active_flag)->toBe(1);
});

it('ne déplace jamais l\'identifiant canonique en ajoutant un champ', function (): void {
    administrateurGouvernance();
    $categorie = categorieGouvernance();

    $this->postJson("/api/v1/admin/categories/{$categorie->id}/fields", [
        'key' => 'couleur',
        'label' => 'Couleur',
        'type' => 'text',
    ])->assertCreated();

    // L'identifiant canonique porte la règle « un identifiant = un
    // enregistrement actif ». Le déplacer sur une catégorie peuplée ferait
    // apparaître des doublons rétroactivement.
    expect(CategoryField::where('asset_category_id', $categorie->id)->where('is_canonical_identifier', true)->count())->toBe(1)
        ->and(CategoryField::where('key', 'couleur')->firstOrFail()->is_canonical_identifier)->toBeFalsy();
});

it('journalise tout changement du catalogue', function (): void {
    administrateurGouvernance();
    $categorie = categorieGouvernance();

    $this->postJson("/api/v1/admin/categories/{$categorie->id}/active", ['active' => false])->assertOk();

    // Le catalogue gouverne ce que toute l'application accepte d'enregistrer :
    // un changement muet rendrait inexplicable un refus survenu le lendemain.
    expect(AuditLog::where('action', 'admin.category_disabled')->count())->toBe(1);
});

it('ferme le catalogue aux agents', function (): void {
    $agent = agentGouvernance();
    $categorie = categorieGouvernance();
    Sanctum::actingAs($agent);

    // Configurer la plateforme n'est pas instruire un dossier.
    $this->getJson('/api/v1/admin/categories')->assertStatus(403);
    $this->postJson("/api/v1/admin/categories/{$categorie->id}/active", ['active' => false])->assertStatus(403);
});

/* -------------------------------------------------------------------- */
/* Équipe et habilitations */
/* -------------------------------------------------------------------- */

it('interdit à un administrateur de modifier sa propre habilitation', function (): void {
    $admin = administrateurGouvernance();

    // Sur une installation qui n'a qu'un compte administrateur, une
    // auto-rétrogradation rendrait le droit irrécupérable — y compris pour
    // celui qui vient de le perdre.
    $this->postJson("/api/v1/admin/team/{$admin->id}/role", [
        'role' => 'user',
        'reason' => 'Essai de rétrogradation de soi-même',
    ])->assertStatus(422);

    expect(User::find($admin->id)?->role)->toBe(UserRole::Admin);
});

it('révoque les jetons en rétrogradant hors du back-office', function (): void {
    administrateurGouvernance();
    $agent = agentGouvernance();
    $agent->createToken('mobile');

    expect($agent->tokens()->count())->toBe(1);

    $this->postJson("/api/v1/admin/team/{$agent->id}/role", [
        'role' => 'user',
        'reason' => 'Fin de mission au back-office',
    ])->assertOk();

    // Sans révocation, l'ancien droit vivrait jusqu'à l'expiration de la
    // session en cours : la rétrogradation ne serait qu'une intention.
    expect(User::find($agent->id)?->role)->toBe(UserRole::User)
        ->and($agent->tokens()->count())->toBe(0);
});

it('journalise l\'ancien ET le nouveau rôle', function (): void {
    administrateurGouvernance();
    $agent = agentGouvernance();

    $this->postJson("/api/v1/admin/team/{$agent->id}/role", [
        'role' => 'admin',
        'reason' => 'Prise de fonction comme administrateur',
    ])->assertOk();

    $entree = AuditLog::where('action', 'admin.role_changed')->firstOrFail();

    // Savoir qu'un changement a eu lieu ne suffit pas à juger s'il a élargi ou
    // restreint l'accès.
    expect($entree->payload['from'])->toBe('agent')
        ->and($entree->payload['to'])->toBe('admin');
});

it("ferme l'écran d'équipe aux agents", function (): void {
    $agent = agentGouvernance();
    $autre = User::create(['phone' => '+2250700001202']);
    Sanctum::actingAs($agent);

    // Un agent ne fabrique pas d'agents : promouvoir crée les comptes qui
    // voient les pièces d'identité et lèvent l'anonymat.
    $this->getJson('/api/v1/admin/team')->assertStatus(403);
    $this->postJson("/api/v1/admin/team/{$autre->id}/role", [
        'role' => 'agent',
        'reason' => 'Tentative de promotion par un agent',
    ])->assertStatus(403);
});

it('déduit les pages du rôle plutôt que de les stocker', function (): void {
    administrateurGouvernance();
    agentGouvernance();

    $reponse = $this->getJson('/api/v1/admin/team')->assertOk();

    $membres = collect($reponse->json('members'))->keyBy('role');

    // Une table d'habilitations serait une seconde source de vérité à côté des
    // middlewares : le jour où elles divergeraient, l'écran afficherait un
    // droit que le code refuse — ou pire, l'inverse.
    expect($membres['agent']['pages'])->not->toContain("Piste d'audit")
        ->and($membres['agent']['pages'])->toContain('Modération')
        ->and($membres['admin']['pages'])->toContain("Piste d'audit");
});

/* -------------------------------------------------------------------- */
/* Écrans de la console */
/* -------------------------------------------------------------------- */

it('refuse les écrans de configuration à un agent connecté à la console', function (): void {
    $agent = agentGouvernance();

    // Le garde est sur la route, pas dans le gabarit : une coquille vide servie
    // à un agent lui apprendrait quand même que l'écran existe, et un oubli de
    // garde côté API resterait invisible.
    foreach (['/admin/categories', '/admin/audit', '/admin/equipe'] as $url) {
        $this->actingAs($agent)->get($url)->assertStatus(403);
    }
});

it('cache à un agent les entrées de navigation qu\'il ne peut pas atteindre', function (): void {
    $agent = agentGouvernance();

    $page = $this->actingAs($agent)->get('/admin/moderation')->assertOk();

    // Afficher un lien que le middleware refusera n'est pas de la transparence,
    // c'est une promesse non tenue.
    expect($page->getContent())->toContain('Modération')
        ->and($page->getContent())->not->toContain("Piste d'audit")
        ->and($page->getContent())->not->toContain('Équipe &amp; rôles');
});

it('sert les écrans de configuration à un administrateur', function (): void {
    $admin = administrateurGouvernance();

    foreach (['/admin/categories', '/admin/audit', '/admin/equipe'] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});

/*
|--------------------------------------------------------------------------
| Corriger une coordonnée mal saisie (06/08/2026)
|--------------------------------------------------------------------------
|
| Une adresse mal tapée à l'inscription enferme son titulaire dehors : le code
| part dans une boîte qui n'existe pas, et il ne peut pas se corriger lui-même
| puisqu'il ne peut pas se connecter.
*/

it('CORRIGE UNE ADRESSE MAL SAISIE', function (): void {
    $compte = User::create(['email' => 'awa@exmple.ci']);
    $compte->forceFill(['email_verified_at' => now()])->save();

    test()->actingAs(administrateurGouvernance())
        ->postJson("/api/v1/admin/users/{$compte->id}/contact", [
            'email' => 'awa@exemple.ci',
            'reason' => 'Faute de frappe constatée au support.',
        ])->assertOk();

    $frais = $compte->fresh();

    expect($frais?->email)->toBe('awa@exemple.ci')
        // MODIFIER N'EST PAS VÉRIFIER : la marque de vérification tombe, sinon
        // on ferait passer pour prouvée une adresse qu'aucun code n'a atteinte.
        ->and($frais?->email_verified_at)->toBeNull();
});

it('REFUSE DE LAISSER UN COMPTE SANS AUCUNE COORDONNÉE', function (): void {
    // Ce ne serait pas une correction mais une suppression déguisée : le
    // titulaire ne pourrait plus jamais se connecter.
    $compte = User::create(['email' => 'awa@exemple.ci']);

    test()->actingAs(administrateurGouvernance())
        ->postJson("/api/v1/admin/users/{$compte->id}/contact", [
            'email' => null,
            'reason' => 'Nettoyage.',
        ])->assertStatus(422);

    expect($compte->fresh()?->email)->toBe('awa@exemple.ci');
});

it('REFUSE UNE COORDONNÉE DÉJÀ PRISE, avec un message lisible', function (): void {
    // Laisser la contrainte de base parler rendrait une erreur 500 illisible,
    // là où l'agent a besoin de savoir QUE la coordonnée est prise.
    User::create(['email' => 'occupee@exemple.ci']);
    $compte = User::create(['email' => 'awa@exemple.ci']);

    test()->actingAs(administrateurGouvernance())
        ->postJson("/api/v1/admin/users/{$compte->id}/contact", [
            'email' => 'occupee@exemple.ci',
            'reason' => 'Fusion demandée.',
        ])->assertStatus(422)
        ->assertJsonPath('message', fn (?string $m): bool => str_contains((string) $m, 'déjà cette coordonnée'));
});

it('N\'INSCRIT PAS LA COORDONNÉE DANS LA CHAÎNE D\'AUDIT', function (): void {
    // La chaîne est inaltérable : un numéro qui y entre devient ineffaçable, et
    // survivrait à toute demande de suppression au titre de la Loi 2013-450.
    $compte = User::create(['email' => 'awa@exemple.ci']);

    test()->actingAs(administrateurGouvernance())
        ->postJson("/api/v1/admin/users/{$compte->id}/contact", [
            'phone' => '+2250700999111',
            'reason' => 'Numéro communiqué par le titulaire.',
        ])->assertOk();

    $entree = AuditLog::where('action', 'admin.user_contact_changed')->latest('id')->first();

    // `payload` est un TABLEAU une fois relu : on le sérialise pour chercher
    // une sous-chaîne, sinon `toContain` compare des éléments entiers et le
    // test passerait même si le numéro y figurait au milieu d'une phrase.
    $serialise = json_encode($entree?->payload, JSON_UNESCAPED_UNICODE);

    expect($entree)->not->toBeNull()
        ->and($serialise)->not->toContain('+2250700999111')
        ->and($serialise)->toContain('Numéro communiqué');
});

it('FERME CETTE CORRECTION à qui n\'est pas du back-office', function (): void {
    $compte = User::create(['email' => 'awa@exemple.ci']);
    $quidam = User::create(['phone' => '+2250700222333']);

    test()->actingAs($quidam)
        ->postJson("/api/v1/admin/users/{$compte->id}/contact", [
            'email' => 'pirate@exemple.ci',
            'reason' => 'Bonjour.',
        ])->assertForbidden();

    expect($compte->fresh()?->email)->toBe('awa@exemple.ci');
});
