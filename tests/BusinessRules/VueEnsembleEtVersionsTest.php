<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AppRelease;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Vue d'ensemble, statistiques d'usage et forçage de mise à jour (lot 4).
 *
 * LA VUE D'ENSEMBLE OUVRE SUR CE QUI ATTEND UNE DÉCISION HUMAINE, et ne
 * désigne jamais personne : ce sont des dénombrements, pas des listes.
 *
 * LES STATISTIQUES NE FABRIQUENT PAS L'INDICATEUR QU'ELLES N'ONT PAS. Le
 * nombre de téléchargements appartient aux magasins d'applications ; le
 * reconstituer à partir des comptes créés donnerait un nombre plausible et
 * faux, sur lequel on déciderait.
 *
 * LE FORÇAGE DE MISE À JOUR NE TOUCHE JAMAIS À LA CONSULTATION. La règle
 * métier absolue n° 1 dit qu'un verdict est gratuit, anonyme et sans compte ;
 * elle ne dit pas « sauf si votre téléphone est vieux ». Seules les écritures
 * sont refusées — exactement les gestes dont les règles ont pu changer.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('device_tokens')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerVueEnsemble();
});
afterEach(fn () => nettoyerVueEnsemble());

function nettoyerVueEnsemble(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'lookups', 'device_tokens', 'asset_status_history',
        'kyc_submissions', 'asset_documents', 'claim_evidences', 'claims', 'assets',
        'companies', 'app_settings', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');

    app(AppRelease::class);
    app(SettingsRepository::class)->fresh();
}

function pilote(UserRole $role = UserRole::Admin): User
{
    // Un numéro par rôle : deux appels dans un même test doivent pouvoir
    // fabriquer deux comptes distincts.
    $compte = User::create(['phone' => '+22507000013'.($role === UserRole::Admin ? '00' : '01')]);
    $compte->forceFill(['role' => $role, 'full_name' => 'Awa Traoré'])->save();
    Sanctum::actingAs($compte);

    return $compte;
}

function bienDuTableau(User $proprietaire, LifeStatus $statut = LifeStatus::Active): Asset
{
    return Asset::create([
        'public_ref' => 'PRV'.random_int(100000000, 999999999),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant = '1M8GDM9AXKP04'.random_int(1000, 9999),
        'identifier_normalized' => $identifiant,
        'active_flag' => 1,
        'life_status' => $statut,
        'registered_at' => now(),
    ]);
}

/* -------------------------------------------------------------------- */
/* Vue d'ensemble */
/* -------------------------------------------------------------------- */

it('compte ce qui attend une décision et dit depuis quand', function (): void {
    $admin = pilote();

    DB::table('kyc_submissions')->insert([
        'user_id' => $admin->id,
        'status' => 'pending',
        'id_front_ref' => 'kyc/epreuve-recto.enc',
        'id_back_ref' => 'kyc/epreuve-verso.enc',
        'selfie_ref' => 'kyc/epreuve-selfie.enc',
        'id_front_sha256' => str_repeat('a', 64),
        'id_back_sha256' => str_repeat('b', 64),
        'selfie_sha256' => str_repeat('c', 64),
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subDays(4),
    ]);

    $reponse = $this->getJson('/api/v1/admin/overview')->assertOk();

    $kyc = collect($reponse->json('queues'))->firstWhere('key', 'kyc');

    // L'ancienneté, et pas seulement le compteur : trois dossiers déposés ce
    // matin et trois oubliés depuis douze jours n'appellent pas la même
    // journée.
    expect($kyc['count'])->toBe(1)
        ->and($kyc['oldest_at'])->not->toBeNull();
});

it('ne désigne personne dans la vue d\'ensemble', function (): void {
    $admin = pilote();
    $bien = bienDuTableau($admin);

    $corps = $this->getJson('/api/v1/admin/overview')->assertOk()->getContent();

    // L'anonymat vaut aussi à l'intérieur (règle métier absolue n° 4) : une vue
    // d'ensemble n'a pas besoin de savoir qui possède quoi pour dire ce qui
    // reste à faire.
    expect($corps)->not->toContain('Awa Traoré')
        ->and($corps)->not->toContain('+2250700001300')
        ->and($corps)->not->toContain($bien->public_ref);
});

it('rend les statuts en langage courant, jamais en code', function (): void {
    $admin = pilote();
    bienDuTableau($admin, LifeStatus::Stolen);

    $statuts = collect($this->getJson('/api/v1/admin/overview')->assertOk()->json('registry.by_status'));

    // CT-04 : « Volé déclaré » côté interface, jamais « V-VOL ».
    $vol = $statuts->firstWhere('value', 'V-VOL');

    expect($vol['count'])->toBe(1)
        ->and($vol['label'])->not->toBe('V-VOL')
        ->and($vol['label'])->toContain('olé');
});

it('ouvre la vue d\'ensemble aux agents', function (): void {
    // C'est l'écran d'ouverture d'une journée d'instruction : le fermer aux
    // agents reviendrait à leur demander de deviner par quoi commencer.
    pilote(UserRole::Agent);

    $this->getJson('/api/v1/admin/overview')->assertOk();
});

/* -------------------------------------------------------------------- */
/* Statistiques d'usage */
/* -------------------------------------------------------------------- */

it('ne fabrique pas le nombre de téléchargements', function (): void {
    pilote();

    $reponse = $this->getJson('/api/v1/admin/app-stats')->assertOk();

    // Un indicateur faux est pire qu'un indicateur absent, parce qu'on décide
    // dessus. L'écran le dit au lieu de reconstituer un chiffre plausible.
    expect($reponse->json())->not->toHaveKey('downloads')
        ->and($reponse->json('notice'))->toContain('magasins d\'applications');
});

it('rend une grille de chaleur complète, même sans consultation', function (): void {
    pilote();

    $carte = $this->getJson('/api/v1/admin/app-stats')->assertOk()->json('heatmap');

    // Une grille trouée se lirait comme une absence de mesure là où il n'y a
    // qu'une absence de consultation.
    expect($carte['grid'])->toHaveCount(7)
        ->and($carte['grid'][0])->toHaveCount(24)
        ->and($carte['days'][0])->toBe('Lundi')
        ->and($carte['max'])->toBe(0);
});

it('ferme les statistiques aux agents', function (): void {
    pilote(UserRole::Agent);

    // Le forçage de mise à jour vit sur cet écran : il met hors service une
    // part du parc installé, ce n'est pas un geste d'instruction.
    $this->getJson('/api/v1/admin/app-stats')->assertStatus(403);
    $this->putJson('/api/v1/admin/app-release', ['minimum_version' => '2.0.0'])->assertStatus(403);
});

/* -------------------------------------------------------------------- */
/* Forçage de mise à jour */
/* -------------------------------------------------------------------- */

it('ne bloque rien tant qu\'aucune version minimale n\'est exigée', function (): void {
    $release = app(AppRelease::class);

    // Une version minimale mal saisie mettrait hors service tout le parc d'un
    // seul réglage : le défaut est donc « aucune exigence ».
    expect($release->minimum())->toBeNull()
        ->and($release->isOutdated('0.0.1'))->toBeFalse();
});

it('refuse les écritures d\'une application trop ancienne', function (): void {
    $admin = pilote();
    app(AppRelease::class)->setMinimum('2.0.0', $admin->id);

    $this->withHeader('X-App-Version', '1.9.9')
        ->postJson('/api/v1/assets', [])
        ->assertStatus(426);
});

it('laisse toujours passer la consultation, quelle que soit la version', function (): void {
    $admin = pilote();
    $bien = bienDuTableau($admin);
    app(AppRelease::class)->setMinimum('2.0.0', $admin->id);

    // Règle métier absolue n° 1 : un verdict est gratuit, anonyme et sans
    // condition. Elle ne dit pas « sauf si votre téléphone est vieux ».
    $this->withHeader('X-App-Version', '0.1.0')
        ->getJson('/api/v1/lookup/'.$bien->identifier_normalized)
        ->assertOk();
});

it('laisse passer les lectures du compte sur une version dépassée', function (): void {
    $admin = pilote();
    app(AppRelease::class)->setMinimum('2.0.0', $admin->id);

    // L'application doit pouvoir expliquer POURQUOI elle ne peut plus écrire :
    // une coquille vide ressemblerait à une panne.
    $this->withHeader('X-App-Version', '1.0.0')
        ->getJson('/api/v1/kyc')
        ->assertOk();
});

it('ne punit pas une version illisible ni une version absente', function (): void {
    $release = app(AppRelease::class);
    $release->setMinimum('2.0.0');

    // Refuser sur un en-tête qu'on n'a pas su lire punirait un utilisateur pour
    // un défaut de la plateforme.
    expect($release->isOutdated('1.4-beta'))->toBeFalse()
        ->and($release->isOutdated(null))->toBeFalse()
        ->and($release->isOutdated(''))->toBeFalse()
        // « 1.4 » et « 1.4.0 » désignent la même version.
        ->and($release->isOutdated('2.0'))->toBeFalse()
        ->and($release->isOutdated('1.10.0'))->toBeTrue()
        ->and($release->isOutdated('10.0.0'))->toBeFalse();
});

it('journalise le forçage avec l\'ancienne et la nouvelle exigence', function (): void {
    $admin = pilote();
    app(AppRelease::class)->setMinimum('1.0.0', $admin->id);
    app(SettingsRepository::class)->fresh();

    $this->putJson('/api/v1/admin/app-release', ['minimum_version' => '2.0.0'])->assertOk();

    $entree = AuditLog::where('action', 'admin.app_release_changed')->firstOrFail();

    // Un forçage met hors service une part du parc : « qui a décidé quoi,
    // quand » est la première question posée le jour où le support est
    // submergé.
    expect($entree->payload['from'])->toBe('1.0.0')
        ->and($entree->payload['to'])->toBe('2.0.0');
});

it('permet de lever l\'exigence en la vidant', function (): void {
    $admin = pilote();
    app(AppRelease::class)->setMinimum('2.0.0', $admin->id);
    app(SettingsRepository::class)->fresh();

    // Manœuvre de repli : un forçage trop large doit pouvoir être défait sans
    // livrer une nouvelle version du serveur.
    $this->putJson('/api/v1/admin/app-release', ['minimum_version' => null])->assertOk();

    expect(app(AppRelease::class)->minimum())->toBeNull();
});

it('annonce publiquement la version exigée, sans authentification', function (): void {
    app(AppRelease::class)->setMinimum('2.0.0');
    app(SettingsRepository::class)->fresh();

    $reponse = $this->getJson('/api/v1/config/app')->assertOk();

    // L'application doit pouvoir afficher son écran de mise à jour au
    // démarrage, plutôt que de laisser l'utilisateur saisir un bien pendant
    // quatre-vingt-dix secondes pour se heurter au refus à l'envoi.
    expect($reponse->json('minimum_version'))->toBe('2.0.0')
        ->and($reponse->json('update_required_for_writes'))->toBeTrue()
        ->and($reponse->json('lookup_always_available'))->toBeTrue();
});

/* -------------------------------------------------------------------- */
/* Écrans de la console */
/* -------------------------------------------------------------------- */

it("ouvre la console sur la vue d'ensemble", function (): void {
    $agent = pilote(UserRole::Agent);

    // Une console qui ouvre sur une liste demande à son lecteur de deviner par
    // quoi commencer.
    $this->actingAs($agent)->get('/admin')->assertRedirect(route('admin.overview'));
    $this->actingAs($agent)->get('/admin/vue-ensemble')->assertOk();
});

it("réserve l'écran des statistiques aux administrateurs", function (): void {
    $agent = pilote(UserRole::Agent);
    $this->actingAs($agent)->get('/admin/statistiques')->assertStatus(403);

    $admin = pilote();
    $this->actingAs($admin)->get('/admin/statistiques')->assertOk();
});

it("n'annonce plus aucun écran « à venir »", function (): void {
    $admin = pilote();

    // Tous les écrans de la maquette sont servis : garder l'affordance
    // entretiendrait une attente que plus rien ne justifie.
    expect($this->actingAs($admin)->get('/admin/vue-ensemble')->assertOk()->getContent())
        ->not->toContain('à venir');
});
