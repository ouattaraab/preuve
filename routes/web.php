<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminConsoleController;
use App\Http\Controllers\Web\FleetAuthController;
use App\Http\Controllers\Web\FleetImportController;
use App\Http\Controllers\Web\PublicLookupController;
use App\Http\Controllers\Web\PublicReportController;
use App\Http\Middleware\EnsureUserHasBackOfficeAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserLeadsAFleet;
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

    // Confidentialité et mentions légales : indexable, et sans session comme le
    // reste du front — la lire ne doit rien coûter en traces.
    Route::get('confidentialite', [PublicLookupController::class, 'privacy'])->name('public.privacy');
    Route::get('conditions', [PublicLookupController::class, 'terms'])->name('public.terms');

    // LE RAPPORT PAYÉ, LISIBLE PARTOUT (ST-0802, règle métier n° 7).
    //
    // L'achat n'exige pas de compte : un trio nom, courriel, téléphone suffit.
    // Sans cette page, un tel acheteur payait et recevait un jeton dans une
    // réponse HTTP — ni application, ni écran, rien à ouvrir. De l'argent
    // entrait, rien d'utilisable n'en sortait.
    //
    // Le jeton est une CAPACITÉ AU PORTEUR, et c'est voulu : un rapport se
    // transmet à son garagiste sans lui créer de compte. La page n'est donc
    // jamais indexée, jamais mise en cache, et ne charge aucune ressource
    // tierce — sans quoi le jeton fuirait par l'en-tête `Referer`.
    Route::get('rapport/{token}', [PublicReportController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{20,64}')
        ->name('public.report');

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
/*
|--------------------------------------------------------------------------
| Espace loueur (EP-07)
|--------------------------------------------------------------------------
|
| L'APPLICATION MOBILE LE PROMET, mot pour mot : « L'import d'un parc entier se
| fait depuis un fichier, sur le web ». Cette page n'existait pas, alors que le
| serveur sait importer depuis le 3 août.
|
| ET C'EST BIEN SUR LE WEB QUE ÇA SE PASSE : un fichier de parc vit sur
| l'ordinateur d'une agence, pas dans le téléphone du gérant.
|
| UN ESPACE DE CLIENT, PAS UN COIN DU BACK-OFFICE : `EnsureUserLeadsAFleet` est
| un gardien distinct de `EnsureUserHasBackOfficeAccess`. Une session de loueur
| n'ouvre ni la piste d'audit, ni la modération, ni le registre.
*/
Route::prefix('flotte')->group(function (): void {
    Route::get('connexion', [FleetAuthController::class, 'show'])->name('fleet.login');
    Route::post('connexion/code', [FleetAuthController::class, 'requestCode'])
        ->middleware('throttle:10,10')->name('fleet.login.request');
    Route::post('connexion/verifier', [FleetAuthController::class, 'verify'])
        ->middleware('throttle:20,10')->name('fleet.login.verify');
    Route::post('deconnexion', [FleetAuthController::class, 'logout'])->name('fleet.logout');

    Route::middleware(['auth', EnsureUserLeadsAFleet::class])->group(function (): void {
        Route::get('import', [FleetImportController::class, 'show'])->name('fleet.import');
        Route::post('import', [FleetImportController::class, 'store'])
            ->middleware('throttle:10,10')->name('fleet.import.store');
        Route::get('import/{import}', [FleetImportController::class, 'status'])
            ->whereNumber('import')->name('fleet.import.status');
    });
});

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
            Route::get('tarifs', [AdminConsoleController::class, 'pricing'])->name('admin.pricing');
        });
    });
});
