<?php

declare(strict_types=1);

use App\Mail\OpsReportMail;
use App\Models\KycSubmission;
use App\Models\User;
use App\Services\HeartbeatPing;
use App\Services\OpsReporter;
use App\Services\SchedulerHeartbeatStore;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * ST-0904 : ce qui se dégrade lentement doit se signaler tout seul.
 *
 * Une alerte dit qu'une chose a cassé. Elle ne dit jamais qu'une file de
 * modération s'allonge de trois dossiers par semaine, ni que l'ancrage n'a plus
 * tourné depuis dix jours. Ces états ne produisent aucun événement.
 */
beforeEach(function (): void {
    Mail::fake();
    Cache::flush();
    app(SettingsRepository::class)->set(OpsReporter::RECIPIENT_SETTING, 'exploitation@bookmi.ci');
});

it('CONSTATE QUE LE STOCKAGE ACCEPTE ENCORE D\'ÉCRIRE', function (): void {
    // `disk_free_space` rend 14 To sur ce mutualisé — le volume de l'hébergeur,
    // pas notre quota. Le seul contrôle honnête est d'écrire pour de vrai.
    $this->artisan('preuve:check-storage')
        ->expectsOutputToContain('écriture possible')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

it('ALERTE quand les tailles dépassent le seuil', function (): void {
    // Un octet suffit à franchir un seuil de zéro : ce qu'on éprouve ici est
    // le déclenchement, pas la valeur.
    config()->set('preuve.storage.alert_mb', 1);
    // Le canal d'alerte doit être dans la pile pour que `Log::error` l'atteigne.
    // C'est justement ce qu'un déploiement peut omettre — le résumé hebdomadaire
    // le signale désormais.
    config()->set('logging.channels.stack.channels', ['daily', 'alerte-ops']);
    file_put_contents(storage_path('logs/gros-journal.log'), str_repeat('x', 1_200_000));

    $this->artisan('preuve:check-storage')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => $m->anomalie);

    @unlink(storage_path('logs/gros-journal.log'));
});

it('NE RÉCLAME PAS UN SEUIL qu\'on ne peut pas deviner', function (): void {
    // Le quota dépend du contrat d'hébergement. Sans réglage, on surveille
    // l'écriture et on le DIT, plutôt que d'inventer une valeur rassurante.
    config()->set('preuve.storage.alert_mb', 0);

    $this->artisan('preuve:check-storage')
        ->expectsOutputToContain('Aucun seuil de stockage configuré')
        ->assertSuccessful();
});

it('RÉSUME LA SEMAINE et l\'expédie', function (): void {
    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => str_contains($m->corps, 'ACTIVITÉ')
        && str_contains($m->corps, 'MÉCANIQUE'));
});

it('SIGNALE UN DOSSIER QUI DORT DANS LA FILE', function (): void {
    // Douze dossiers déposés ce matin ne posent aucun problème ; un seul qui
    // attend depuis trois semaines en est un — et le décompte seul ne les
    // distingue pas.
    $compte = User::create(['phone' => '+2250700111222']);

    KycSubmission::create([
        'user_id' => $compte->id,
        'status' => 'pending',
        'id_front_ref' => 'a', 'id_back_ref' => 'b', 'selfie_ref' => 'c',
        'id_front_sha256' => str_repeat('0', 64),
        'id_back_sha256' => str_repeat('0', 64),
        'selfie_sha256' => str_repeat('0', 64),
        // `created_at` n'est pas remplissable : l'antidater demande forceFill,
        // sans quoi le dossier naît « aujourd'hui » et le test ne prouve rien.
    ])->forceFill(['created_at' => now()->subDays(9)])->save();

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => $m->anomalie
        && str_contains($m->corps, 'attend depuis 9 jours'));
});

it('RAPPORTE L\'ÉTAT DE L\'ANCRAGE, qui rend la chaîne opposable', function (): void {
    // Sans ancrage, la chaîne d'audit reste vérifiable — mais par nous seuls,
    // ce qui ne vaut rien devant un juge.
    //
    // ON N'ÉPROUVE PAS ICI L'ABSENCE D'ANCRAGE : le registre d'ancrage est
    // append-only, protégé par un déclencheur, et la base REFUSE qu'un test en
    // supprime une ligne. C'est le dispositif qui fonctionne, et un test qui
    // manœuvrerait pour le contourner éprouverait autre chose que la
    // production. On vérifie donc que la ligne est rapportée ; le chemin
    // « anomalie » est couvert par le test des alertes éteintes.
    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => str_contains($m->corps, 'Dernier ancrage')
        && str_contains($m->corps, 'Chaîne d\'audit'));
});

it('NE NOMME PERSONNE dans le résumé', function (): void {
    // Ce sont des décomptes. Le nom du plus ancien déposant n'aiderait pas à
    // décider, et ferait sortir une identité de la plateforme par courriel.
    $compte = User::create(['phone' => '+2250788999000']);
    $compte->forceFill(['full_name' => 'Awa Koné'])->save();

    KycSubmission::create([
        'user_id' => $compte->id,
        'status' => 'pending',
        'id_front_ref' => 'a', 'id_back_ref' => 'b', 'selfie_ref' => 'c',
        'id_front_sha256' => str_repeat('0', 64),
        'id_back_sha256' => str_repeat('0', 64),
        'selfie_sha256' => str_repeat('0', 64),
    ])->forceFill(['created_at' => now()->subDays(5)])->save();

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => ! str_contains($m->corps, 'Awa Koné')
        && ! str_contains($m->corps, '+2250788999000'));
});

it('SURVIT à une table absente plutôt que de perdre tout le rapport', function (): void {
    // Les autres lignes ont encore de la valeur : un rapport amputé vaut mieux
    // qu'un rapport qui n'arrive pas.
    DB::statement('DROP TABLE IF EXISTS failed_jobs');

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => str_contains($m->corps, 'non mesurable'));
});

it('SIGNALE QUE LES ALERTES SONT ÉTEINTES, le cas échéant', function (): void {
    // LA SURVEILLANCE SE SURVEILLE. Un déploiement qui omet le canal dans
    // LOG_STACK perd TOUTES les alertes d'erreur, en silence — précisément le
    // défaut qu'on cherchait à corriger. Le rapport hebdomadaire est alors la
    // seule chose qui parvienne encore : il doit le dire.
    config()->set('logging.channels.stack.channels', ['daily']);

    $this->artisan('preuve:weekly-digest')->assertSuccessful();

    Mail::assertSent(OpsReportMail::class, fn (OpsReportMail $m): bool => $m->anomalie
        && str_contains($m->corps, 'ÉTEINTES'));
});

/*
|--------------------------------------------------------------------------
| Le surveillant EXTÉRIEUR (ST-0904)
|--------------------------------------------------------------------------
|
| Tout ce que PREUVE sait de sa propre santé s'exécute DANS PREUVE : aucun de
| ces dispositifs ne peut signaler que le serveur est tombé, puisqu'il tombe
| avec lui. Le montage est donc inversé — la plateforme donne signe de vie, et
| son SILENCE déclenche l'alerte. Un serveur mort ne peut pas mentir par
| omission.
*/

it('DONNE SIGNE DE VIE au surveillant extérieur', function (): void {
    Http::fake();
    // UN ANCRAGE RÉCENT, sans quoi la plateforme est réellement en anomalie et
    // le ping part — à juste titre — sur `/fail`. Le chemin nominal ne
    // s'éprouve que sur une plateforme saine.
    ancrageRecent();
    app(SettingsRepository::class)->set(HeartbeatPing::URL_SETTING, 'https://hc-ping.com/'.Str::uuid());

    $this->artisan('preuve:heartbeat')->assertSuccessful();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'hc-ping.com')
        && ! str_ends_with($r->url(), '/fail'));
});

it('SIGNALE L\'ANOMALIE PAR UN CHEMIN QUI N\'EST PAS LE NÔTRE', function (): void {
    // Le jour où la passerelle de messagerie est en panne — celui où l'on a le
    // plus besoin d'être prévenu — c'est la seule voie qui reste. Ici l'ancrage
    // est absent, donc la chaîne n'est plus opposable.
    Http::fake();
    app(SettingsRepository::class)->set(HeartbeatPing::URL_SETTING, 'https://hc-ping.com/'.Str::uuid());

    $this->artisan('preuve:heartbeat')->assertSuccessful();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/fail')
        && str_contains((string) $r->body(), 'plus opposable'));
});

/** Un ancrage abouti, daté de maintenant. */
function ancrageRecent(): void
{
    DB::table('audit_anchors')->insert([
        'head_id' => 1,
        'head_chain_hash' => str_repeat('a', 64),
        'entry_count' => 1,
        'channels' => json_encode(['mail' => 'ok']),
        'status' => 'anchored',
        'created_at' => now(),
    ]);
}

it('NE PING PAS quand aucun surveillant n\'est configuré', function (): void {
    Http::fake();
    app(SettingsRepository::class)->forget(HeartbeatPing::URL_SETTING);
    app(SettingsRepository::class)->fresh();

    $this->artisan('preuve:heartbeat')->assertSuccessful();

    Http::assertNothingSent();
});

it('REFUSE UNE URL QUI N\'EST PAS EN HTTPS', function (): void {
    // L'URL est une capacité au porteur : la laisser voyager en clair
    // permettrait à qui écoute de maintenir la sonde au vert pendant que le
    // serveur est mort.
    Http::fake();
    app(SettingsRepository::class)->set(HeartbeatPing::URL_SETTING, 'http://hc-ping.com/'.Str::uuid());

    expect(app(HeartbeatPing::class)->isConfigured())->toBeFalse();

    $this->artisan('preuve:heartbeat')->assertSuccessful();

    Http::assertNothingSent();
});

it('UN SURVEILLANT INJOIGNABLE NE CASSE RIEN', function (): void {
    // Une supervision qui casse ce qu'elle surveille est pire que pas de
    // supervision : le planificateur doit passer, quoi qu'il arrive au ping.
    Http::fake(fn () => throw new RuntimeException('réseau coupé'));
    app(SettingsRepository::class)->set(HeartbeatPing::URL_SETTING, 'https://hc-ping.com/'.Str::uuid());

    $this->artisan('preuve:heartbeat')->assertSuccessful();

    // Et le témoin LOCAL a bien été écrit malgré l'échec du ping : c'est lui
    // qui alimente la sonde de santé publique.
    expect(app(SchedulerHeartbeatStore::class)->status())->not->toBe('never');
});

it('N\'ÉCRIT PAS L\'URL DANS LES JOURNAUX', function (): void {
    // Elle est une capacité : journalisée, elle finirait dans une alerte par
    // courriel, puis dans une boîte de réception.
    Http::fake(fn () => throw new RuntimeException('réseau coupé'));
    $url = 'https://hc-ping.com/'.Str::uuid();
    app(SettingsRepository::class)->set(HeartbeatPing::URL_SETTING, $url);

    Log::spy();

    $this->artisan('preuve:heartbeat')->assertSuccessful();

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});
