<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

/**
 * Une invocation en double du planificateur ne doit rien exécuter deux fois.
 *
 * CONSTATÉ EN PRODUCTION LE 04/08/2026 : deux sauvegardes à 01:30:07 et
 * 01:30:10, deux ancrages à 02:40:06 et 02:40:09 — le planificateur était
 * invoqué DEUX FOIS à trois secondes d'intervalle.
 *
 * `withoutOverlapping()` n'y peut rien : il empêche un chevauchement, or la
 * première exécution était terminée avant que la seconde ne commence. Seul
 * `onOneServer()` prend un verrou porté par la tâche ET la minute, et le garde
 * jusqu'à la fin de celle-ci.
 *
 * La cause est en amont — une tâche cron déclarée deux fois chez l'hébergeur —
 * et elle ne nous appartient pas : une protection qui dépend d'une console
 * tierce n'est pas une protection.
 */
it('protège toute tâche planifiée contre une double invocation', function (): void {
    $sansVerrou = collect(app(Schedule::class)->events())
        ->reject(fn (Event $tache): bool => $tache->onOneServer)
        ->map(fn (Event $tache): string => (string) $tache->command)
        ->values()
        ->all();

    expect($sansVerrou)->toBe(
        [],
        'Ces tâches s\'exécuteraient deux fois si le planificateur était invoqué '.
        'deux fois dans la même minute : '.implode(', ', $sansVerrou)
    );
});

it('dispose du magasin de verrous que cette protection exige', function (): void {
    // `onOneServer()` sans magasin capable de verrouiller lève une exception et
    // ferait tomber le planificateur ENTIER — la protection casserait ce
    // qu'elle protège.
    expect(Schema::hasTable('cache_locks'))->toBeTrue();
});
