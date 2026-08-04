<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Console d'administration (lot 1).
 *
 * ELLE S'AUTHENTIFIE PAR SESSION, PAS PAR JETON. Elle affiche des cartes
 * grises et des pièces d'identité : un jeton rangé dans le navigateur serait
 * lisible par la première faille XSS, quand le cookie de session est
 * `httpOnly`.
 *
 * MÊME PARCOURS QUE LES UTILISATEURS : téléphone puis code à usage unique. Y
 * introduire un mot de passe reviendrait à créer, sur les comptes les plus
 * sensibles, la seule chose que la plateforme avait éliminée.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerConsole();
    Mail::fake();

    $reglages = app(SettingsRepository::class);
    $reglages->set(ConfigurableOtpSender::PROVIDER_KEY, 'mail');
    $reglages->fresh();
});
afterEach(fn () => nettoyerConsole());

function nettoyerConsole(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'app_settings', 'otp_codes', 'sessions', 'personal_access_tokens', 'users'] as $table) {
        if (Schema::hasTable($table)) {
            DB::statement("TRUNCATE TABLE {$table}");
        }
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function compteConsole(UserRole $role, string $email = 'agent@preuve.ci'): User
{
    $compte = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);
    $compte->forceFill(['role' => $role, 'email' => $email, 'full_name' => 'Amara Kouassi'])->save();

    return $compte;
}

function codeConsole(): string
{
    $capture = '';

    Mail::assertSent(OtpCodeMail::class, function ($mail) use (&$capture): bool {
        $capture = $mail->code;

        return true;
    });

    return $capture;
}

it('ferme la console à qui n\'est pas connecté', function (): void {
    test()->get('/admin/moderation')->assertRedirect('/admin/connexion');
    test()->get('/admin/supervision')->assertRedirect('/admin/connexion');
});

it('ouvre la console à un agent, par code à usage unique', function (): void {
    $agent = compteConsole(UserRole::Agent);

    test()->post('/admin/connexion/code', ['phone' => $agent->phone])->assertRedirect();
    test()->post('/admin/connexion/verifier', [
        'phone' => $agent->phone,
        'code' => codeConsole(),
    ])->assertRedirect('/admin/moderation');

    test()->get('/admin/moderation')->assertOk()->assertSee('Modération');
});

it('refuse la console à un utilisateur ordinaire, code valide compris', function (): void {
    // LE contrôle. Un code correct prouve la maîtrise du canal, pas le droit
    // d'entrer : le rôle est vérifié APRÈS, jamais avant — refuser à
    // l'émission apprendrait quels numéros sont ceux d'administrateurs.
    $simple = compteConsole(UserRole::User, 'simple@exemple.ci');

    test()->post('/admin/connexion/code', ['phone' => $simple->phone])->assertRedirect();

    test()->post('/admin/connexion/verifier', [
        'phone' => $simple->phone,
        'code' => codeConsole(),
    ])->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('ne dit pas si un compte existe quand on demande un code', function (): void {
    // Une réponse qui varierait ferait de cette page un service d'énumération
    // des numéros d'administrateurs.
    $connu = compteConsole(UserRole::Admin);

    $reponseConnu = test()->post('/admin/connexion/code', ['phone' => $connu->phone]);
    $reponseInconnu = test()->post('/admin/connexion/code', ['phone' => '+2250799887766']);

    expect($reponseInconnu->status())->toBe($reponseConnu->status())
        ->and(session('message'))->toContain('Si ce compte');
});

it('n\'expédie aucun code à un numéro inconnu', function (): void {
    test()->post('/admin/connexion/code', ['phone' => '+2250799887766']);

    Mail::assertNothingSent();
});

it('n\'écrit jamais de jeton dans la page', function (): void {
    // La console ne doit pas déposer de credential réutilisable dans le
    // navigateur : sa seule preuve d'identité est le cookie de session.
    $admin = compteConsole(UserRole::Admin);

    test()->post('/admin/connexion/code', ['phone' => $admin->phone]);
    test()->post('/admin/connexion/verifier', ['phone' => $admin->phone, 'code' => codeConsole()]);

    $page = test()->get('/admin/moderation')->assertOk()->getContent();

    expect($page)->not->toContain('localStorage')
        ->and($page)->not->toContain('Bearer ')
        // Le jeton CSRF, lui, doit être là : il protège les écritures.
        ->and($page)->toContain('csrf-token');
});

it('déconnecte et invalide la session', function (): void {
    $admin = compteConsole(UserRole::Admin);

    test()->post('/admin/connexion/code', ['phone' => $admin->phone]);
    test()->post('/admin/connexion/verifier', ['phone' => $admin->phone, 'code' => codeConsole()]);

    test()->post('/admin/deconnexion')->assertRedirect('/admin/connexion');

    expect(auth()->check())->toBeFalse();

    test()->get('/admin/moderation')->assertRedirect('/admin/connexion');
});

it('sert tous les écrans de la maquette à un administrateur', function (): void {
    // Les neuf écrans de la maquette existent désormais : la navigation ne
    // porte plus aucune entrée inerte. Une entrée qui ne mène nulle part
    // entretiendrait une attente que plus rien ne justifie.
    $admin = compteConsole(UserRole::Admin);

    test()->post('/admin/connexion/code', ['phone' => $admin->phone]);
    test()->post('/admin/connexion/verifier', ['phone' => $admin->phone, 'code' => codeConsole()]);

    $page = test()->get('/admin/moderation')->assertOk();

    foreach ([
        "Vue d'ensemble", 'Modération', 'Registre des biens', 'Catégories & champs',
        'Utilisateurs', 'Statistiques app', 'Supervision', "Piste d'audit", 'Équipe & rôles',
    ] as $ecran) {
        $page->assertSee($ecran);
    }

    $page->assertDontSee('à venir');
});

it('périme le code après usage', function (): void {
    $admin = compteConsole(UserRole::Admin);

    test()->post('/admin/connexion/code', ['phone' => $admin->phone]);
    $code = codeConsole();

    test()->post('/admin/connexion/verifier', ['phone' => $admin->phone, 'code' => $code]);
    test()->post('/admin/deconnexion');

    // Rejouer le même code ne doit rien ouvrir, et l'agent doit LIRE pourquoi
    // plutôt que de tomber sur une erreur serveur.
    test()->post('/admin/connexion/verifier', ['phone' => $admin->phone, 'code' => $code])
        ->assertRedirect('/admin/connexion')
        ->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();

    expect(OtpCode::whereNull('consumed_at')->count())->toBe(0);
});

it('ne loge aucune ressource sous un préfixe de route', function (): void {
    /*
     * CONSTATÉ EN PRODUCTION LE 04/08/2026 : la console avait ses polices et
     * son script dans `public/admin/`. Le serveur y résolvait `/admin/` —
     * dossier réel, sans index et sans listage autorisé — et rendait un 403
     * AVANT que Laravel ne voie la requête. L'écran de connexion restait
     * accessible, seule la racine de la console était murée : le défaut ne se
     * voyait donc que pour qui tapait l'adresse à la main.
     *
     * Aucun test d'intégration ne pouvait l'attraper : le serveur de test de
     * PHPUnit ne sert pas de fichiers statiques et passe tout à Laravel.
     * Celui-ci regarde donc le disque, pas les réponses.
     */
    $prefixes = collect(Route::getRoutes())
        ->map(fn ($route) => strtok((string) $route->uri(), '/'))
        ->filter(fn ($segment) => is_string($segment) && $segment !== '' && ! str_starts_with($segment, '{'))
        ->unique();

    foreach ($prefixes as $prefixe) {
        expect(is_dir(public_path($prefixe)))->toBeFalse(
            "Le dossier public/{$prefixe} masque le préfixe de route « {$prefixe} » : ".
            'le serveur le résoudra avant Laravel.'
        );
    }
});
