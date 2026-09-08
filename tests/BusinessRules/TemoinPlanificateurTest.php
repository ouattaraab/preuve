<?php

declare(strict_types=1);

use App\Services\SchedulerHeartbeatStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Témoin de passage du planificateur (ST-0904).
 *
 * Un planificateur arrêté ne casse rien tout de suite : il fait simplement que
 * ce qui devait arriver dans le temps n'arrive pas. Les biens provisoires ne
 * deviennent jamais actifs, les transferts n'expirent plus, la chaîne cesse
 * d'être ancrée — et tout paraît fonctionner.
 *
 * Constaté le 03/08/2026 sur l'hébergement : les tâches cron ne s'exécutaient
 * pas, sans une erreur ni un journal. Il a fallu écrire un fichier témoin et
 * l'observer pour s'en apercevoir.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::table('app_settings')->where('key', 'scheduler.last_run_at')->delete();
});

it('distingue « jamais démarré » de « arrêté »', function (): void {
    // Jamais démarré est un défaut d'installation ; s'être arrêté est un
    // incident. Les confondre enverrait chercher au mauvais endroit.
    $temoin = app(SchedulerHeartbeatStore::class);

    expect($temoin->status())->toBe('never');

    $temoin->touch();
    expect($temoin->status())->toBe('ok');

    DB::table('app_settings')->where('key', 'scheduler.last_run_at')
        ->update(['value' => json_encode(now()->subHour()->toIso8601String())]);

    expect(app(SchedulerHeartbeatStore::class)->status())->toBe('stale');
});

it('expose l\'état du planificateur dans la sonde publique', function (): void {
    // La sonde est sans authentification : c'est elle qu'un superviseur externe
    // interroge, et c'est donc là que l'arrêt doit se voir.
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.scheduler', 'never');

    Artisan::call('preuve:heartbeat');

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.scheduler', 'ok');
});

it('survit à un vidage de cache', function (): void {
    // Le témoin est écrit en BASE, pas en cache : un cache se vide au
    // déploiement, et un témoin qui disparaît tout seul se lirait comme un
    // planificateur arrêté. Une alerte qui crie au loup finit ignorée.
    Artisan::call('preuve:heartbeat');
    Artisan::call('cache:clear');

    expect(app(SchedulerHeartbeatStore::class)->status())->toBe('ok');
});

it('n\'écrit rien dans la chaîne d\'audit', function (): void {
    // Un passage de planificateur n'est pas une action métier attestable, et le
    // journaliser ferait passer un battement régulier par le verrou nommé dont
    // le plafond mesuré est d'une quinzaine d'actions simultanées.
    $avant = DB::table('audit_log')->count();

    Artisan::call('preuve:heartbeat');

    expect(DB::table('audit_log')->count())->toBe($avant);
});
