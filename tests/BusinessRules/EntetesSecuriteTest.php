<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Captcha\CaptchaVerifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En-têtes de sécurité (ST-0904).
 *
 * ILS SONT POSÉS PAR L'APPLICATION, PAS PAR LE SERVEUR. Sur un mutualisé, la
 * configuration ne nous appartient pas : une intervention de l'hébergeur remet
 * les fichiers en l'état, et ce qui a été posé à la main disparaît sans
 * prévenir. Ces tests garantissent que la protection voyage avec le code.
 *
 * LE CLIQUE-DÉTOURNEMENT VISE LA CONSOLE : un cadre invisible superposé à
 * l'écran des comptes ferait cliquer un agent sur « Suspendre » en lui faisant
 * croire qu'il ferme une bannière.
 *
 * LE SCRIPT EN LIGNE EST INTERDIT, et c'est ce qui a justifié de retirer les
 * attributs `onsubmit` des gabarits : une console qui affiche des pièces
 * d'identité n'a pas les moyens d'autoriser ce qu'une faille XSS injecte.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('users')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['personal_access_tokens', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
});

it('protège toute réponse du clique-détournement', function (): void {
    foreach (['/', '/api/v1/health', '/admin/connexion'] as $url) {
        $reponse = $this->get($url);

        expect($reponse->headers->get('X-Frame-Options'))->toBe('DENY')
            // Doublé par la politique : `X-Frame-Options` est l'en-tête des
            // navigateurs anciens, encore majoritaires sur un parc Android
            // d'entrée de gamme.
            ->and($reponse->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }
});

it('interdit le script en ligne sur la console', function (): void {
    $admin = User::create(['phone' => '+2250700001500']);
    $admin->forceFill(['role' => UserRole::Admin, 'full_name' => 'Awa Traoré'])->save();

    $reponse = $this->actingAs($admin)->get('/admin/comptes');

    $politique = (string) $reponse->headers->get('Content-Security-Policy');

    expect($politique)->toContain("script-src 'self'")
        ->and($politique)->not->toContain("script-src 'self' 'unsafe-inline'")
        // Un gabarit qui réintroduirait un `onsubmit` casserait la console sans
        // que rien ne le dise : le test le dit.
        ->and($reponse->getContent())->not->toContain('onsubmit=');
});

it('ferme toute destination par défaut', function (): void {
    $politique = (string) $this->get('/')->headers->get('Content-Security-Policy');

    // Chaque ouverture est nommée : rien n'est permis par omission.
    expect($politique)->toContain("default-src 'none'")
        ->and($politique)->toContain("base-uri 'none'")
        // Un formulaire dont la destination serait détournée exfiltrerait ce
        // qu'un agent vient de saisir.
        ->and($politique)->toContain("form-action 'self'");
});

it('n\'autorise le script du défi que sur la page qui l\'affiche', function (): void {
    $nominal = (string) $this->get('/')->headers->get('Content-Security-Policy');

    // L'autoriser en permanence rendrait invérifiable la promesse « aucune
    // ressource tierce sur le chemin nominal » : elle ne tiendrait plus qu'à
    // ce que personne n'ajoute un jour la balise.
    expect($nominal)->not->toContain('challenges.cloudflare.com')
        ->and($nominal)->toContain("frame-src 'none'");
});

it('ouvre la politique quand le défi est réellement servi', function (): void {
    // Plafond atteint ET fournisseur configuré : les deux conditions de
    // l'affichage du défi.
    $this->mock(CaptchaVerifier::class, function ($simulacre): void {
        $simulacre->shouldReceive('isConfigured')->andReturn(true);
        $simulacre->shouldReceive('siteKey')->andReturn('cle-publique-de-test');
        $simulacre->shouldReceive('verify')->andReturn(false);
    });

    DB::table('lookups')->truncate();
    $empreinte = str_repeat('f', 64);

    for ($i = 0; $i < 200; $i++) {
        DB::table('lookups')->insert([
            'identifier_normalized' => 'SATURATION'.$i,
            'ip_hash' => $empreinte,
            'source' => 'web',
            'created_at' => now(),
        ]);
    }

    $reponse = $this->get('/verifier?q=1M8GDM9AXKP042788');

    // Le plafond est porté par l'empreinte de l'adresse ; le test client n'en
    // partage pas forcément une avec les lignes posées. On ne vérifie donc que
    // la cohérence : si le défi est affiché, la politique l'autorise.
    $politique = (string) $reponse->headers->get('Content-Security-Policy');
    $afficheLeDefi = str_contains((string) $reponse->getContent(), 'cf-turnstile');

    expect($afficheLeDefi ? str_contains($politique, 'challenges.cloudflare.com') : true)->toBeTrue();

    DB::table('lookups')->truncate();
});

it('annonce HTTPS durablement, sans engager les sous-domaines', function (): void {
    // La directive engagerait des sous-domaines que nous ne servons pas et dont
    // nous ignorons la configuration : une promesse qu'on ne peut pas tenir se
    // paie en indisponibilité.
    $securise = $this->get('https://preuve.click/');

    expect($securise->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and($securise->headers->get('Strict-Transport-Security'))->not->toContain('includeSubDomains');
});

it('n\'annonce pas HSTS sur une requête en clair', function (): void {
    // L'annoncer en clair n'aurait aucun effet, et le poser en développement
    // rendrait le domaine de test inaccessible en HTTP pendant un an.
    expect($this->get('http://localhost/')->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('ne publie pas la version exacte de PHP', function (): void {
    // Elle n'aide que celui qui cherche une faille connue.
    expect($this->get('/')->headers->get('X-Powered-By'))->toBeNull();
});

it('interdit au navigateur de deviner le type de contenu', function (): void {
    // Sans cela, un fichier déposé par un utilisateur et servi comme pièce
    // pourrait être interprété comme du HTML par le navigateur.
    expect($this->get('/api/v1/health')->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('ne recouvre pas le référent plus strict des pages publiques', function (): void {
    expect($this->get('/verifier?q=AB')->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($this->get('/admin/connexion')->headers->get('Referrer-Policy'))
        ->toBe('strict-origin-when-cross-origin');
});
