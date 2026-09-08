<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front public de consultation (ST-0306).
 *
 * IL NE DOIT JAMAIS DEVENIR UN SECOND CHEMIN PLUS PERMISSIF QUE L'API. Une
 * page web qui contournerait le plafond horaire ferait d'elle-même l'outil de
 * balayage du registre que ce plafond existe pour empêcher.
 *
 * SEULES LES PAGES `/b/{référence}` SONT INDEXABLES. La page de résultat porte
 * l'identifiant réel dans son URL : l'indexer publierait, moteur après moteur,
 * l'annuaire des numéros de châssis enregistrés.
 *
 * ELLE NE DIT RIEN DU DÉTENTEUR NI DE L'IDENTIFIANT COMPLET (règle métier
 * absolue n° 4), et n'exige jamais de compte (règle n° 1).
 */
beforeEach(function (): void {
    if (! Schema::hasTable('lookups')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerFrontPublic();
});
afterEach(fn () => nettoyerFrontPublic());

function nettoyerFrontPublic(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'lookups', 'asset_status_history', 'assets', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienDuFrontPublic(LifeStatus $statut = LifeStatus::Active): Asset
{
    $proprietaire = User::create(['phone' => '+2250700001400']);
    $proprietaire->forceFill(['full_name' => 'Kouadio Yao'])->save();

    return Asset::create([
        // Même forme que la vraie : « PRV- » suivi de huit caractères d'un
        // alphabet sans O, I ni L — on la dicte au téléphone.
        'public_ref' => 'PRV-'.substr(str_shuffle('23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 8),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'life_status' => $statut,
        'registered_at' => now()->subMonths(3),
    ]);
}

it('sert la consultation sans compte, en deux gestes', function (): void {
    // Règle métier absolue n° 1 : un champ, un bouton, aucun compte.
    $this->get('/')
        ->assertOk()
        ->assertSee('Vérifier')
        ->assertSee('action="/verifier"', false);
});

it('rend le verdict en langage courant, jamais en code', function (): void {
    $bien = bienDuFrontPublic(LifeStatus::Stolen);

    $page = $this->get('/b/'.$bien->public_ref)->assertOk();

    // CT-04 : « Volé déclaré », jamais « V-VOL ».
    $page->assertSee('ATTENTION')
        ->assertSee('Volé déclaré')
        ->assertDontSee('V-VOL');
});

it('ne divulgue ni le détenteur ni l\'identifiant complet', function (): void {
    $bien = bienDuFrontPublic();

    $corps = $this->get('/b/'.$bien->public_ref)->assertOk()->getContent();

    // L'anonymat est symétrique et sans exception : ni le nom, ni le numéro,
    // ni le châssis complet ne sortent de cette page.
    expect($corps)->not->toContain('Kouadio Yao')
        ->and($corps)->not->toContain('+2250700001400')
        ->and($corps)->not->toContain('1M8GDM9AXKP042788');
});

it('indexe la page de référence publique et jamais celle de résultat', function (): void {
    $bien = bienDuFrontPublic();

    $reference = $this->get('/b/'.$bien->public_ref)->assertOk();
    $resultat = $this->get('/verifier?q=1M8GDM9AXKP042788')->assertOk();

    // L'URL de résultat porte l'identifiant réel : l'indexer constituerait
    // l'annuaire des numéros enregistrés, moteur après moteur.
    expect($reference->headers->get('X-Robots-Tag'))->toBe('index, follow')
        ->and($resultat->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($resultat->getContent())->toContain('name="robots"')
        ->and($reference->getContent())->toContain('rel="canonical"');
});

it('n\'expose aucun plan de site énumérant les références', function (): void {
    // Chaque page prise isolément est anodine ; leur collection ne l'est pas.
    $this->get('/sitemap.xml')->assertNotFound();

    $robots = file_get_contents(public_path('robots.txt'));

    expect($robots)->not->toContain('Sitemap:')
        ->and($robots)->toContain('Disallow: /verifier');
});

it('ne laisse pas fuir l\'identifiant vérifié dans le référent', function (): void {
    $reponse = $this->get('/verifier?q=1M8GDM9AXKP042788');

    // La page ne charge aucune ressource tierce ; l'en-tête garantit qu'un lien
    // suivi depuis elle n'emporte pas l'identifiant chez qui n'a pas à le
    // connaître.
    expect($reponse->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('ne rend jamais un identifiant inconnu rassurant', function (): void {
    $page = $this->get('/verifier?q=1M8GDM9AXKP042788')->assertNotFound();

    // Un bien non enregistré n'est ni un bon ni un mauvais signe : le
    // présenter comme rassurant ferait acheter un bien volé que personne n'a
    // déclaré.
    $page->assertSee('PAS ENREGISTRÉ')
        ->assertDontSee('Aucun signalement');
});

it('compte les consultations web dans le même plafond que l\'API', function (): void {
    $bien = bienDuFrontPublic();

    $this->get('/b/'.$bien->public_ref)->assertOk();

    // Même service, même empreinte, même quota : un second chemin qui ne
    // journaliserait pas ses consultations serait un contournement du plafond.
    expect(DB::table('lookups')->where('source', 'web')->count())->toBe(1);
});

it('refuse une saisie inexploitable sans prétendre avoir cherché', function (): void {
    // Deux caractères : trop court pour être un identifiant, et cela ne
    // consomme donc pas le quota — c'est une faute de frappe, pas un balayage.
    $this->get('/verifier?q=AB')
        ->assertStatus(422)
        ->assertSee('NUMÉRO ILLISIBLE');
});

it('borne la route indexable à la forme opaque des références', function (): void {
    // La route ne doit jamais accepter un identifiant réel : ce serait une URL
    // indexable portant le numéro du bien.
    $this->get('/b/1M8GDM9AXKP042788')->assertNotFound();
});

it('ne charge aucune ressource tierce sur le chemin nominal', function (): void {
    $bien = bienDuFrontPublic();

    foreach (['/', '/b/'.$bien->public_ref] as $url) {
        $corps = (string) $this->get($url)->getContent();

        // CT-05 : utilisable en 3G. Et une page qui n'appelle personne d'autre
        // ne peut divulguer à personne d'autre ce que le visiteur est venu
        // vérifier.
        expect($corps)->not->toContain('<script')
            ->and($corps)->not->toContain('//challenges.cloudflare.com')
            ->and($corps)->not->toContain('googleapis');
    }
});

it('ne dépose aucun cookie sur le visiteur', function (): void {
    $bien = bienDuFrontPublic();

    foreach (['/', '/b/'.$bien->public_ref, '/verifier?q=AB'] as $url) {
        $reponse = $this->get($url);

        // Un identifiant de session permettrait de recoudre les consultations
        // successives d'un même visiteur — exactement ce que le hachage
        // quotidien de l'adresse IP existe pour empêcher.
        expect($reponse->headers->getCookies())->toBeEmpty();
    }
});

it('autorise la mise en cache partagée d\'une page de statut', function (): void {
    $bien = bienDuFrontPublic();

    // Sans cela, la page la plus consultée du site serait recalculée à chaque
    // visite. Une minute : assez pour absorber les rafales, assez peu pour
    // qu'une déclaration de vol devienne visible tout de suite.
    expect($this->get('/b/'.$bien->public_ref)->headers->get('Cache-Control'))
        ->toContain('max-age=60');
});

it('compose un titre et une description propres pour les moteurs', function (): void {
    $bien = bienDuFrontPublic(LifeStatus::Stolen);

    $corps = (string) $this->get('/b/'.$bien->public_ref)->assertOk()->getContent();

    preg_match('/<title>(.*?)<\/title>/s', $corps, $titre);

    // Une section Blade multiligne emporte ses retours à la ligne dans la
    // balise : cela ne se voit qu'en lisant le HTML rendu, puis dans les
    // résultats de recherche.
    expect($titre[1] ?? '')->not->toContain("\n")
        ->and($titre[1] ?? '')->toStartWith('Volé déclaré')
        ->and($corps)->toContain('content="Statut déclaré du bien '.$bien->public_ref);
});

it('PUBLIE DES CONDITIONS D\'UTILISATION, indexables', function (): void {
    // Un magasin d'applications les exige, et un utilisateur doit pouvoir les
    // citer. Elles disent d'abord ce que PREUVE N'EST PAS : la méprise la plus
    // coûteuse serait qu'un acheteur croie avoir acquis une garantie.
    test()->get('/conditions')
        ->assertOk()
        ->assertSee('Conditions d\'utilisation')
        ->assertSee('registre déclaratif')
        ->assertSee('BookMi')
        ->assertHeader('X-Robots-Tag', 'index, follow');
});

it('DIT QU\'UN BIEN NON ENREGISTRÉ N\'EST PAS UN BIEN SAIN', function (): void {
    // L'absence d'information n'est pas une information rassurante, et c'est le
    // contresens qui ferait acheter un bien volé que personne n'a déclaré.
    test()->get('/conditions')
        ->assertOk()
        ->assertSee('n\'est pas un bien sain', false)
        ->assertSee('ne prouve pas qu\'une personne est propriétaire', false);
});

it('RAPPELLE LES DEUX ANONYMATS dans les conditions', function (): void {
    // Règle métier absolue n° 4, dans les deux sens. Un utilisateur doit
    // pouvoir opposer ce texte à la plateforme.
    $page = test()->get('/conditions')->assertOk();

    $page->assertSee('n\'est jamais divulguée', false)
        ->assertSee('y compris lorsqu\'un rapport est acheté', false);
});

it('OUVRE LES CONDITIONS DEPUIS N\'IMPORTE QUELLE PAGE', function (): void {
    // Une page qu'on ne peut atteindre depuis nulle part n'existe pas.
    foreach (['/', '/confidentialite'] as $depuis) {
        test()->get($depuis)->assertOk()->assertSee('href="/conditions"', false);
    }
});
