<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminConsoleController;
use App\Http\Controllers\Web\FleetAuthController;
use App\Http\Controllers\Web\FleetImportController;
use App\Http\Controllers\Web\PaymentReturnController;
use App\Http\Controllers\Web\PublicLookupController;
use App\Http\Controllers\Web\PublicReportController;
use App\Http\Controllers\Web\ReportPurchaseController;
use App\Http\Controllers\Web\StolenListPageController;
use App\Http\Controllers\Web\TransferInvitationController;
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
    // Le plafond métier compte les identifiants DISTINCTS par heure ; celui-ci
    // borne le DÉBIT de la route elle-même. Le premier protège le registre du
    // balayage, le second protège le serveur du martèlement — ce ne sont pas
    // les mêmes attaques, et le second manquait.
    Route::get('verifier', [PublicLookupController::class, 'verify'])
        ->middleware('throttle:60,1')
        ->name('public.verify');

    // Confidentialité et mentions légales : indexable, et sans session comme le
    // reste du front — la lire ne doit rien coûter en traces.
    Route::get('confidentialite', [PublicLookupController::class, 'privacy'])->name('public.privacy');
    Route::get('conditions', [PublicLookupController::class, 'terms'])->name('public.terms');

    /*
     * LA LISTE PUBLIQUE DES BIENS VOLÉS (ST-0805).
     *
     * INDEXABLE, contrairement à la page de résultat d'une consultation : elle
     * ne contient que ce que des détenteurs ont DEMANDÉ à rendre public, et la
     * faire trouver par un moteur est exactement le service qu'ils ont payé.
     */
    Route::get('voles', [StolenListPageController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('public.stolen');

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
        // Le jeton fait quarante caractères : il ne se devine pas. Le plafond
        // ne protège donc pas d'une force brute, mais du COÛT d'y prétendre —
        // chaque tentative est une requête en base, gratuite pour l'attaquant
        // et payée par un mutualisé.
        ->middleware('throttle:30,1')
        ->name('public.report');

    /*
     * OÙ L'OPÉRATEUR RAMÈNE LE PAYEUR (ST-0801, ST-0805).
     *
     * `ReportController` désignait déjà cette adresse, qui n'existait nulle
     * part : après avoir réglé, l'acheteur tombait sur un 404. Il avait payé,
     * la plateforme avait encaissé, et l'écran lui disait que la page était
     * introuvable.
     *
     * ELLE NE DÉCIDE RIEN, ELLE RELIT : le retour du navigateur ne prouve aucun
     * paiement — seul le webhook signé fait foi.
     */
    Route::get('paiement/retour', [PaymentReturnController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('public.payment.return');

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
/*
 * LES DEUX PAGES QUI ONT BESOIN D'UNE SESSION — et pourquoi elles sont ICI,
 * hors du groupe sans cookie.
 *
 * La consultation est anonyme et le restera : y déposer un identifiant de
 * session permettrait de recoudre les consultations successives d'un visiteur,
 * exactement ce que le hachage quotidien de l'IP existe pour empêcher. Ces
 * deux pages-ci sont d'une autre nature : le visiteur s'y identifie
 * DÉLIBÉRÉMENT — il donne son nom pour acheter un rapport, ou il accepte une
 * cession qui lui est nominativement adressée. Un cookie y est cohérent, et il
 * y est nécessaire : sans session, pas de jeton CSRF sur des formulaires qui
 * engagent un paiement ou un changement de propriétaire, et rien pour retenir
 * les coordonnées entre l'envoi du code et sa vérification.
 *
 * La frontière est donc nette : cookie UNIQUEMENT quand le visiteur choisit de
 * se nommer. Les pages de consultation restent cacheables et sans trace.
 */
/*
 * ACHETER LE RAPPORT DÉTAILLÉ DEPUIS LE WEB (ST-0801, ST-0802).
 *
 * La page de verdict s'arrêtait sur « Vérifier un autre bien » — au moment
 * exact où quelqu'un, debout devant une moto, voudrait en savoir plus. Tout
 * existait côté serveur, l'application mobile le proposait ; le canal le
 * plus accessible, un navigateur sans installation, ne vendait rien.
 *
 * ADRESSÉE PAR LA RÉFÉRENCE PUBLIQUE, jamais par l'identifiant interne :
 * l'acheteur ne connaît que ce que le verdict lui a montré.
 */
Route::get('rapport/commander/{publicRef}', [ReportPurchaseController::class, 'show'])
    ->where('publicRef', 'PRV-[A-Z0-9]{8}')
    ->middleware('throttle:60,1')
    ->name('public.report.order');
Route::post('rapport/commander/{publicRef}/code', [ReportPurchaseController::class, 'requestCode'])
    ->where('publicRef', 'PRV-[A-Z0-9]{8}')
    // Plus serré : chaque envoi coûte un message, et le plafond de l'OTP
    // ne protège qu'une destination à la fois.
    ->middleware('throttle:10,1')
    ->name('public.report.code');
Route::post('rapport/commander/{publicRef}', [ReportPurchaseController::class, 'purchase'])
    ->where('publicRef', 'PRV-[A-Z0-9]{8}')
    ->middleware('throttle:12,1')
    ->name('public.report.buy');

/*
 * ACCEPTER UNE CESSION SANS AVOIR L'APPLICATION (ST-0601).
 *
 * Au marché, le vendeur a l'application et l'acheteur non. Sans ce chemin,
 * l'acheteur reçoit un courriel qui lui demande d'installer une application
 * pour valider une vente déjà conclue — et sept jours plus tard la cession
 * expire, le bien reste au vendeur, et l'acheteur détient un bien qui n'est
 * pas à son nom.
 *
 * DEUX FACTEURS : le jeton prouve qu'on a reçu le courriel, le code qu'on
 * lit cette boîte à l'instant. Jamais indexée — la page décrit un bien
 * identifiable et une transaction en cours.
 */
Route::get('cession/{token}', [TransferInvitationController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:60,1')
    ->name('public.transfer.invite');
Route::post('cession/{token}', [TransferInvitationController::class, 'confirm'])
    ->where('token', '[a-f0-9]{64}')
    // Plus serré que la lecture : c'est ici qu'on présente un code à six
    // chiffres, et le plafond de l'OTP ne protège que la destination — pas
    // le nombre de jetons qu'un attaquant essaierait en parallèle.
    ->middleware('throttle:12,1')
    ->name('public.transfer.confirm');

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
