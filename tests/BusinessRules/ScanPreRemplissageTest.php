<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\ScanOutcome;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\CategoryRegistry;
use App\Services\Scan\DocumentReader;
use App\Services\Scan\ScanExtraction;
use App\Services\TelemetryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0202 : pré-remplissage de l'identifiant par scan.
 *
 * Le scan PROPOSE, il n'enregistre jamais, et il ne propose que ce qu'il peut
 * garantir. Un identifiant mal lu est pire qu'un identifiant non lu : la
 * saisie manuelle se relit, une proposition se valide sans être vérifiée —
 * personne ne recompte dix-sept caractères.
 *
 * N'utilise pas RefreshDatabase : l'enregistrement écrit dans la chaîne
 * d'audit, protégée par des déclencheurs.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('document_scans')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerScans();
    categorieScan();

    // Enregistré ici, et une seule fois : voir LecteurDeTest.
    app()->singleton(DocumentReader::class, fn (): DocumentReader => new LecteurDeTest);
});
afterEach(fn () => nettoyerScans());

function nettoyerScans(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'document_scans', 'lookups', 'notifications', 'asset_status_history',
        'assets', 'category_fields', 'asset_categories', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function categorieScan(): void
{
    $categorie = AssetCategory::create([
        'key' => 'voiture', 'name' => 'Voiture', 'icon' => '🚗', 'position' => 1, 'is_active' => true,
    ]);

    CategoryField::create([
        'asset_category_id' => $categorie->id, 'key' => 'vin', 'label' => 'Châssis',
        'type' => 'identifier', 'is_required' => true, 'is_canonical_identifier' => true, 'position' => 1,
    ]);

    app(CategoryRegistry::class)->publish();
}

/**
 * Lecteur de test MUTABLE, enregistré une seule fois par test.
 *
 * Il ne peut pas être remplacé en cours de test : `Route::getController()`
 * garde en cache l'instance du contrôleur, qui garde elle-même le lecteur
 * qu'on lui a injecté à la première requête. Un re-binding n'atteindrait donc
 * jamais la deuxième requête, et un test qui enchaîne plusieurs scans
 * mesurerait quatre fois le premier. C'est un artefact du harnais — en
 * production, chaque requête part d'une application neuve.
 */
final class LecteurDeTest implements DocumentReader
{
    public ScanExtraction $extraction;

    public function __construct()
    {
        $this->extraction = ScanExtraction::unreadable();
    }

    public function read(UploadedFile $image): ScanExtraction
    {
        return $this->extraction;
    }
}

/** Règle ce que le prochain scan lira. */
function lecteurRendant(ScanExtraction $extraction): void
{
    $lecteur = app(DocumentReader::class);

    if ($lecteur instanceof LecteurDeTest) {
        $lecteur->extraction = $extraction;
    }
}

function scanner(): TestResponse
{
    return test()->postJson('/api/v1/assets/scan', [
        'doc_type' => 'registration_card',
        'file' => UploadedFile::fake()->image('carte-grise.jpg'),
    ]);
}

function utilisateurScan(string $numero = '+2250701020304'): User
{
    $utilisateur = User::create(['phone' => $numero]);
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

it('ne propose jamais un châssis dont le chiffre de contrôle est faux', function (): void {
    // LE test de la story. Un VIN dont l'OCR a manqué un caractère cesse d'être
    // un VIN et retomberait dans le fourre-tout « numéro de série », qui
    // accepte n'importe quelle chaîne d'au moins six caractères. Il serait
    // alors proposé quand même, et l'utilisateur enregistrerait un châssis
    // inexistant sans aucun moyen de s'en apercevoir.
    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9A1KP042788'], confidence: 98));

    scanner()
        ->assertOk()
        ->assertJsonPath('identifier', null);
});

it('retient un châssis valide trouvé dans le texte brut de la page', function (): void {
    // Le chiffre de contrôle est ce qui autorise à faire confiance à une chaîne
    // ramassée sans étiquette : dix-sept caractères qui le vérifient ne sont
    // pas là par hasard.
    utilisateurScan();
    lecteurRendant(new ScanExtraction(tokens: ['REPUBLIQUE', '1M8GDM9AXKP042788', 'ABIDJAN']));

    scanner()
        ->assertOk()
        ->assertJsonPath('identifier.value', '1M8GDM9AXKP042788')
        ->assertJsonPath('identifier.type', 'vin');
});

it('ne retient pas un mot quelconque ramassé dans le texte', function (): void {
    // Sans distinction d'origine, « ABIDJAN01 » passerait pour un numéro de
    // série : le fourre-tout accepte toute chaîne d'au moins six caractères.
    utilisateurScan();
    lecteurRendant(new ScanExtraction(tokens: ['ABIDJAN01', 'PREFECTURE', 'MINISTERE']));

    scanner()
        ->assertOk()
        ->assertJsonPath('identifier', null);
});

it('préfère le châssis à la plaque quand les deux sont lisibles', function (): void {
    // Une plaque change de propriétaire et de véhicule ; un châssis, jamais.
    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['AA123BC', '1M8GDM9AXKP042788']));

    scanner()->assertJsonPath('identifier.type', 'vin');
});

it('répond normalement quand le document est illisible', function (): void {
    // Une carte grise pliée, photographiée de nuit : le cas ordinaire. Rendre
    // une erreur ferait afficher un écran d'échec là où l'utilisateur doit
    // simplement saisir son identifiant — le chemin nominal.
    utilisateurScan();
    lecteurRendant(ScanExtraction::unreadable());

    $reponse = scanner()
        ->assertOk()
        ->assertJsonPath('identifier', null);

    expect($reponse->json('message'))->toContain('Saisissez');
});

it('n\'écrit jamais l\'identifiant proposé en clair', function (): void {
    // La table de mesure ne doit pas devenir un registre parallèle de tous les
    // châssis photographiés, alimenté par des gens qui n'ont peut-être jamais
    // enregistré leur bien (règle métier absolue n° 8, minimisation).
    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    scanner()->assertOk();

    $lignes = DB::table('document_scans')->get();

    expect($lignes)->toHaveCount(1);

    foreach ($lignes as $ligne) {
        foreach ((array) $ligne as $valeur) {
            expect((string) $valeur)->not->toContain('1M8GDM9AXKP042788');
        }
    }
});

it('ne crée aucun bien', function (): void {
    // Un enregistrement direct sur la foi d'un OCR créerait des biens au
    // mauvais châssis, donc des doublons impossibles à démêler — sur la règle
    // même qui fonde le registre (règle métier absolue n° 3).
    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    scanner()->assertOk();

    expect(Asset::count())->toBe(0);
});

it('exige un compte pour scanner', function (): void {
    // Le scan consomme un appel facturé chez un tiers et traite un document
    // nominatif : c'est une écriture, pas une consultation.
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    test()->postJson('/api/v1/assets/scan', [
        'doc_type' => 'registration_card',
        'file' => UploadedFile::fake()->image('carte-grise.jpg'),
    ])->assertStatus(401);
});

it('signale immédiatement qu\'un enregistrement actif existe', function (): void {
    // Prévenir maintenant évite à l'utilisateur de remplir un formulaire de 90
    // secondes pour se heurter à un refus à la fin. Cela ne divulgue rien : la
    // consultation de statut est publique, gratuite et anonyme.
    $autre = User::create(['phone' => '+2250709998877']);

    Asset::create([
        'public_ref' => 'PRV-ABCD1234',
        'owner_user_id' => $autre->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Stolen,
        'registered_at' => now()->subMonth(),
    ]);

    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    $reponse = scanner()->assertOk();

    expect($reponse->json('existing_asset.public_ref'))->toBe('PRV-ABCD1234')
        // Le propriétaire du bien existant n'est jamais divulgué (règle
        // métier absolue n° 4).
        ->and(json_encode($reponse->json('existing_asset')))->not->toContain('+2250709998877');
});

it('mesure une proposition gardée comme acceptée', function (): void {
    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    $scanId = scanner()->assertOk()->json('scan_id');

    test()->postJson('/api/v1/assets', [
        'category' => 'voiture',
        'attributes' => ['vin' => '1m8gdm9axkp042788'],
        'scan_id' => $scanId,
    ])->assertStatus(201);

    // Comparée après normalisation : la casse et les tirets ne sont pas une
    // correction de l'utilisateur.
    expect(DocumentScan::find($scanId)?->outcome)->toBe(ScanOutcome::Accepted);
});

it('mesure une proposition modifiée comme corrigée', function (): void {
    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    $scanId = scanner()->assertOk()->json('scan_id');

    test()->postJson('/api/v1/assets', [
        'category' => 'voiture',
        'attributes' => ['vin' => 'JHMCM56557C404453'],
        'scan_id' => $scanId,
    ])->assertStatus(201);

    expect(DocumentScan::find($scanId)?->outcome)->toBe(ScanOutcome::Corrected);
});

it('compte un doublon refusé comme un pré-remplissage réussi', function (): void {
    // La lecture était bonne : c'est l'enregistrement qui était impossible. Ne
    // compter que les succès fausserait la mesure dans le sens qui arrange.
    $autre = User::create(['phone' => '+2250709998877']);

    Asset::create([
        'public_ref' => 'PRV-EFGH5678',
        'owner_user_id' => $autre->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);

    utilisateurScan();
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    $scanId = scanner()->assertOk()->json('scan_id');

    test()->postJson('/api/v1/assets', [
        'category' => 'voiture',
        'attributes' => ['vin' => '1M8GDM9AXKP042788'],
        'scan_id' => $scanId,
    ])->assertStatus(409);

    expect(DocumentScan::find($scanId)?->outcome)->toBe(ScanOutcome::Accepted);
});

it('n\'attribue pas le scan d\'autrui à sa propre saisie', function (): void {
    // Sans ce filtre, un client pourrait faire passer sa saisie manuelle pour
    // le succès du scan de quelqu'un d'autre et brouiller la mesure.
    utilisateurScan('+2250700000011');
    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    $scanId = scanner()->assertOk()->json('scan_id');

    utilisateurScan('+2250700000022');

    test()->postJson('/api/v1/assets', [
        'category' => 'voiture',
        'attributes' => ['vin' => '1M8GDM9AXKP042788'],
        'scan_id' => $scanId,
    ])->assertStatus(201);

    expect(DocumentScan::find($scanId)?->outcome)->toBe(ScanOutcome::Pending);
});

it('distingue le taux de lecture du taux d\'exactitude', function (): void {
    // Un scan qui lit toujours quelque chose mais se trompe une fois sur trois
    // est pire qu'un scan qui n'ose rien : c'est le second taux qui doit
    // décider du maintien de la fonction.
    utilisateurScan();

    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));
    $garde = scanner()->json('scan_id');

    test()->postJson('/api/v1/assets', [
        'category' => 'voiture',
        'attributes' => ['vin' => '1M8GDM9AXKP042788'],
        'scan_id' => $garde,
    ])->assertStatus(201);

    lecteurRendant(new ScanExtraction(candidates: ['JHMCM56557C404453']));
    $corrige = scanner()->json('scan_id');

    test()->postJson('/api/v1/assets', [
        'category' => 'voiture',
        'attributes' => ['vin' => '1HGCM82633A004352'],
        'scan_id' => $corrige,
    ])->assertStatus(201);

    // Un scan illisible, et un scan lisible que personne n'utilise.
    lecteurRendant(ScanExtraction::unreadable());
    scanner()->assertOk();

    lecteurRendant(new ScanExtraction(candidates: ['JH4TB2H26CC000000']));
    scanner()->assertOk();

    $mesure = app(TelemetryService::class)->scanPrefill(now()->subDay());

    expect($mesure['scans'])->toBe(4)
        // Trois scans sur quatre ont produit une proposition…
        ->and($mesure['prefill_rate'])->toBe(0.75)
        // …mais une proposition sur deux utilisées était fausse.
        ->and($mesure['accuracy_rate'])->toBe(0.5)
        // Et deux propositions n'ont mené nulle part.
        ->and($mesure['abandoned'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Scan SANS COMPTE, depuis la consultation (ST-0202 × ST-0301)
|--------------------------------------------------------------------------
|
| Celui à qui l'on propose une moto sur un parking n'a pas de compte, et c'est
| lui à qui recopier dix-sept caractères de châssis coûte le plus. Exiger un
| compte ici démentirait la promesse du produit au moment exact où elle compte.
*/

/** Un bien actif, détenu par quelqu'un d'autre, prêt à être consulté. */
function bienScanneEnregistre(): void
{
    $autre = User::create(['phone' => '+2250709998866']);

    Asset::create([
        'public_ref' => 'PRV-SCAN0001',
        'owner_user_id' => $autre->id,
        'asset_category_key' => 'voiture',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Stolen,
        'registered_at' => now()->subMonth(),
    ]);
}

function scannerSansCompte(): TestResponse
{
    return test()->postJson('/api/v1/lookup/scan', [
        'doc_type' => 'registration_card',
        'file' => UploadedFile::fake()->image('carte-grise.jpg'),
    ]);
}

it('LIT UNE CARTE GRISE SANS AUCUN COMPTE', function (): void {
    lecteurRendant(new ScanExtraction(
        candidates: ['1M8GDM9AXKP042788'],
        attributes: ['marque' => 'Yamaha'],
        confidence: 92,
    ));

    scannerSansCompte()
        ->assertOk()
        ->assertJsonPath('identifier.value', '1M8GDM9AXKP042788')
        ->assertJsonPath('rate_limited', false);
});

it('NE DIVULGUE PAS LE STATUT DU BIEN, même s\'il est enregistré', function (): void {
    // Le scan d'enregistrement rend `existing_asset` pour éviter un formulaire
    // de 90 secondes voué au refus. Ici ce champ serait une CONSULTATION
    // DÉGUISÉE : elle échapperait au journal des consultations, donc aux
    // compteurs de 30 jours, à l'alerte de pic, et au plafond horaire lui-même.
    bienScanneEnregistre();

    lecteurRendant(new ScanExtraction(
        candidates: ['1M8GDM9AXKP042788'],
        attributes: [],
        confidence: 95,
    ));

    $reponse = scannerSansCompte()->assertOk();

    expect($reponse->json())->not->toHaveKey('existing_asset');
    expect(json_encode($reponse->json()))->not->toContain('life_status');
});

it('n\'attache la trace à personne', function (): void {
    // La trace mesure si le pré-remplissage sert. Elle ne doit désigner
    // personne : le scan a eu lieu sans compte, et rien ne doit permettre d'y
    // rattacher quelqu'un après coup.
    lecteurRendant(new ScanExtraction(
        candidates: ['1M8GDM9AXKP042788'],
        attributes: [],
        confidence: 88,
    ));

    scannerSansCompte()->assertOk();

    expect(DocumentScan::latest('id')->first()?->user_id)->toBeNull();
});

it('BORNE LA DÉPENSE, séparément du plafond de consultation', function (): void {
    // Un scan appelle un fournisseur qui facture à l'appel ; une consultation
    // lit la base. Les compter ensemble ferait qu'un après-midi de
    // vérifications au marché épuise le budget d'extraction de la plateforme.
    config()->set('preuve.scan_rate_limit.anonymous_per_hour', 2);

    lecteurRendant(new ScanExtraction(
        candidates: ['1M8GDM9AXKP042788'],
        attributes: [],
        confidence: 90,
    ));

    scannerSansCompte()->assertOk();
    scannerSansCompte()->assertOk();

    scannerSansCompte()
        ->assertStatus(429)
        ->assertJsonPath('rate_limited', true)
        // LA SAISIE MANUELLE RESTE OUVERTE, et le message le dit : c'est le
        // raccourci qui est plafonné, jamais la vérification elle-même.
        ->assertJsonPath('captcha', null);
});

it('COMPTE AUSSI LES DOCUMENTS ILLISIBLES', function (): void {
    // Le fournisseur facture dès l'appel. Ne compter que les lectures réussies
    // laisserait un automate envoyer des images vides indéfiniment, en payant
    // à chaque fois, sans jamais atteindre le plafond.
    config()->set('preuve.scan_rate_limit.anonymous_per_hour', 1);

    lecteurRendant(new ScanExtraction(candidates: [], attributes: [], confidence: null, failed: true));

    scannerSansCompte()->assertOk()->assertJsonPath('identifier', null);
    scannerSansCompte()->assertStatus(429);
});

it('LA CONSULTATION RESTE LIBRE quand le scan est plafonné', function (): void {
    // Le plafond porte sur le raccourci, pas sur la promesse. Quelqu'un qui a
    // épuisé ses scans doit pouvoir taper le numéro et obtenir son verdict.
    config()->set('preuve.scan_rate_limit.anonymous_per_hour', 0);
    bienScanneEnregistre();

    lecteurRendant(new ScanExtraction(candidates: [], attributes: [], confidence: null, failed: true));

    scannerSansCompte()->assertStatus(429);

    test()->getJson('/api/v1/lookup/1M8GDM9AXKP042788')
        ->assertOk()
        ->assertJsonPath('found', true);
});

it('ZÉRO COUPE LA DÉPENSE, et ne rétablit pas le réglage par défaut', function (): void {
    // Un exploitant met 0 quand son budget d'extraction est épuisé ou que le
    // fournisseur est en panne. Un repli naïf rallumerait les appels payants
    // qu'il vient d'arrêter, et il ne le découvrirait qu'à la facture. Le
    // projet a déjà payé ce piège sur les tarifs.
    config()->set('preuve.scan_rate_limit.anonymous_per_hour', 0);

    lecteurRendant(new ScanExtraction(candidates: ['1M8GDM9AXKP042788']));

    scannerSansCompte()->assertStatus(429);

    expect(DocumentScan::count())->toBe(0, 'Aucun appel au fournisseur ne doit avoir eu lieu.');
});
