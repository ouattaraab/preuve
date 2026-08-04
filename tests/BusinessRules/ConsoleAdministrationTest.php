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

it('permet réellement de trancher un dossier d\'identité', function (): void {
    // UNE FILE DE REVUE QUI NE DÉCIDE RIEN NE SERT À RIEN. Quelqu'un dépose sa
    // pièce d'identité, un agent la voit passer, et personne ne peut la valider :
    // la promesse « examiné sous 48 heures » ne tient alors à rien, et le niveau
    // de fiabilité d'un bien ne monte jamais.
    $console = file_get_contents(public_path('console/app.js'));

    expect($console)
        ->toContain('/review')
        ->toContain('verified')
        ->toContain('suspected_forgery')
        // Les trois images du dossier doivent être atteignables : une décision
        // prise sans les voir se prendrait sur un numéro de ligne.
        ->toContain('id_front')
        ->toContain('id_back')
        ->toContain('selfie');
});

it('n\'attache aucun gestionnaire en attribut, que la politique interdit', function (): void {
    // `script-src 'self'` sans `'unsafe-inline'` : une console qui affiche des
    // pièces d'identité n'a pas les moyens d'autoriser ce qu'une faille XSS
    // injecterait. Les gestionnaires se posent en JavaScript, jamais en HTML.
    $console = file_get_contents(public_path('console/app.js'));

    expect($console)->not->toContain('onclick=')
        ->and($console)->not->toContain('onsubmit=');

    foreach (glob(resource_path('views/admin/*.blade.php')) ?: [] as $gabarit) {
        expect(file_get_contents($gabarit))->not->toContain('onclick=', message: $gabarit);
    }
});

it('laisse revoir un dossier déjà tranché', function (): void {
    // UN DOSSIER VALIDÉ DISPARAISSAIT SANS TRACE CONSULTABLE : l'agent qui
    // venait de le trancher ne pouvait ni le revoir, ni relire le motif qu'il
    // avait écrit. Une décision qu'on ne peut pas relire n'est pas contestable.
    $console = file_get_contents(public_path('console/app.js'));
    $gabarit = file_get_contents(resource_path('views/admin/moderation.blade.php'));

    expect($gabarit)->toContain('data-etat')
        ->and($console)->toContain('?status=')
        // Une falsification suspectée est un état distinct d'un simple refus :
        // les confondre ferait disparaître celui qu'on relit le plus.
        ->and($console)->toContain('suspected_forgery');
});

it('affiche le nom déclaré en face des pièces d\'identité', function (): void {
    // Une revue d'identité qui ne montre pas le nom déclaré ne vérifie rien :
    // l'agent constate qu'une image existe, il ne peut pas la COMPARER.
    $console = file_get_contents(public_path('console/app.js'));

    expect($console)->toContain('DÉCLARÉ PAR LA PERSONNE')
        ->toContain('full_name')
        // Et il doit dire quoi faire de cette comparaison, pas seulement
        // l'afficher.
        ->toContain('Compare ce nom');
});

it('sert l\'écran des tarifs aux administrateurs seuls', function (): void {
    // Les tarifs décident de ce que les gens paient : un agent instruit des
    // dossiers, il ne fixe pas les prix.
    $admin = User::create(['phone' => '+2250700000031']);
    $admin->forceFill(['role' => UserRole::Admin])->save();

    $this->actingAs($admin)->get('/admin/tarifs')->assertOk()->assertSee('Tarifs', false);

    $agent = User::create(['phone' => '+2250700000032']);
    $agent->forceFill(['role' => UserRole::Agent])->save();

    $this->actingAs($agent)->get('/admin/tarifs')->assertStatus(403);
});

it('n\'affiche jamais la clé secrète dans l\'écran des tarifs', function (): void {
    // Une clé qu'on peut relire est une clé qui fuit au premier accès indu.
    $gabarit = file_get_contents(resource_path('views/admin/pricing.blade.php'));
    $console = file_get_contents(public_path('console/app.js'));

    expect($gabarit)->toContain('type="password"')
        ->and($console)->toContain("champ.value = ''")
        // Et zéro doit se lire comme une décision, pas comme un champ vide.
        ->and($console)->toContain('gratuit pour tout le monde');
});
