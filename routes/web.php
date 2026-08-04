<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminConsoleController;
use App\Http\Controllers\Web\PublicLookupController;
use App\Http\Middleware\EnsureUserHasBackOfficeAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * Front public de consultation (ST-0306).
 *
 * Gratuit, anonyme, SANS compte (règle métier absolue n° 1) : n'ajouter jamais
 * de middleware d'authentification ici. Le plafond horaire est tenu par
 * `LookupService`, le MÊME que l'API — un second chemin qui le contournerait
 * ferait de ces pages l'outil de balayage du registre que le plafond existe
 * pour empêcher.
 */
Route::withoutMiddleware([
    // SANS SESSION, DONC SANS COOKIE. Trois raisons, et la première suffirait :
    // consulter est anonyme, et déposer un identifiant de session sur le
    // navigateur d'un visiteur permettrait de recoudre ses consultations
    // successives — exactement ce que le hachage quotidien de l'adresse IP
    // existe pour empêcher (Loi 2013-450).
    // Ensuite, un `Set-Cookie` interdit toute mise en cache partagée : la page
    // de statut la plus consultée serait recalculée à chaque visite.
    // Enfin, ces pages n'ont aucun formulaire d'écriture — il n'y a rien à
    // protéger par jeton CSRF.
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
    AddQueuedCookiesToResponse::class,
])->group(function (): void {
    Route::get('/', [PublicLookupController::class, 'home'])->name('public.home');
    Route::get('verifier', [PublicLookupController::class, 'verify'])->name('public.verify');

    // Page indexable, adressée par la référence publique OPAQUE. Le motif borne
    // la route à cette forme : elle ne doit jamais servir d'identifiant réel.
    Route::get('b/{publicRef}', [PublicLookupController::class, 'asset'])
        ->where('publicRef', 'PRV-[A-Z0-9]{8}')
        ->name('public.asset');
});

/*
 * Espace administrateur.
 *
 * Servi par la même application que l'API : la console interroge
 * `/api/v1/admin/*` avec le cookie de session, et non un jeton rangé dans le
 * navigateur. Cette console affiche des cartes grises et des pièces
 * d'identité — un jeton en `localStorage` serait lisible par la première
 * faille XSS, le cookie de session est `httpOnly`.
 */
Route::prefix('admin')->group(function (): void {
    Route::get('connexion', [AdminAuthController::class, 'show'])->name('admin.login');

    // Plafonds distincts : demander un code coûte un envoi, le vérifier ouvre
    // une session. Le second est la cible d'une attaque par force brute.
    Route::post('connexion/code', [AdminAuthController::class, 'requestCode'])
        ->middleware('throttle:10,10')->name('admin.login.request');
    Route::post('connexion/verifier', [AdminAuthController::class, 'verify'])
        ->middleware('throttle:20,10')->name('admin.login.verify');

    Route::post('deconnexion', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::middleware(['auth', EnsureUserHasBackOfficeAccess::class])->group(function (): void {
        // La console ouvre sur ce qui attend une décision, pas sur une liste.
        Route::get('/', fn () => redirect()->route('admin.overview'));
        Route::get('vue-ensemble', [AdminConsoleController::class, 'overview'])->name('admin.overview');
        Route::get('moderation', [AdminConsoleController::class, 'moderation'])->name('admin.moderation');
        Route::get('registre', [AdminConsoleController::class, 'registry'])->name('admin.registry');
        Route::get('comptes', [AdminConsoleController::class, 'users'])->name('admin.users');
        Route::get('supervision', [AdminConsoleController::class, 'monitoring'])->name('admin.monitoring');

        /*
         * Écrans de configuration : administrateurs seulement, comme les API
         * qui les alimentent. Le garde est posé ICI et non dans le gabarit :
         * une coquille vide servie à un agent lui apprendrait quand même que
         * l'écran existe, et un oubli de garde côté API resterait invisible.
         */
        Route::middleware(EnsureUserIsAdmin::class)->group(function (): void {
            Route::get('categories', [AdminConsoleController::class, 'categories'])->name('admin.categories');
            Route::get('audit', [AdminConsoleController::class, 'audit'])->name('admin.audit');
            Route::get('equipe', [AdminConsoleController::class, 'team'])->name('admin.team');
            Route::get('statistiques', [AdminConsoleController::class, 'stats'])->name('admin.stats');
        });
    });
});
