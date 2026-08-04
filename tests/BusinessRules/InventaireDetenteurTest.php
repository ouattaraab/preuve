<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TransferStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Transfer;
use App\Models\User;
use App\Services\PlatformState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Inventaire du détenteur (`GET /assets`) et transferts le concernant
 * (`GET /transfers`).
 *
 * CES DEUX ROUTES SONT LE CHAÎNON MANQUANT DES CLIENTS. Déclarer un vol, céder
 * ou réclamer passent tous par `/assets/{id}/…` : sans inventaire, un
 * particulier n'avait aucun moyen de connaître l'identifiant interne de ses
 * propres biens, et sans liste de transferts, l'acheteur — dont l'invitation
 * est un simple code par SMS — n'avait aucun moyen de connaître le numéro du
 * transfert à confirmer.
 *
 * CE QUE CES TESTS SURVEILLENT VRAIMENT, c'est que le confort ainsi ouvert ne
 * fasse pas une brèche dans la règle métier absolue n° 4 : on rend à quelqu'un
 * ce qu'il a saisi, jamais ce qui appartient à un autre.
 *
 * N'utilise pas RefreshDatabase : la bascule de maintenance écrit dans la
 * chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('transfers')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerInventaire();
});
afterEach(function (): void {
    app(PlatformState::class)->setReadOnly(false, null);
    nettoyerInventaire();
});

function nettoyerInventaire(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'app_settings', 'notifications', 'transfers', 'asset_status_history',
        'assets', 'otp_codes', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function detenteurInventaire(string $telephone = '+2250700000001'): User
{
    $utilisateur = User::create(['phone' => $telephone]);

    $utilisateur->forceFill([
        'kyc_status' => KycStatus::Verified->value,
        'kyc_verified_at' => now()->subMonth(),
    ])->save();

    return $utilisateur;
}

function bienInventaire(
    User $detenteur,
    string $identifiant = '1M8GDM9AXKP042788',
    ?LifeStatus $statut = null,
    bool $actif = true,
): Asset {
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $detenteur->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => $actif ? 1 : null,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut ?? LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);
}

it('ne rend que les biens du porteur du jeton', function (): void {
    // LE test de cette route. Un inventaire qui déborderait sur le voisin
    // rétablirait, du côté authentifié, exactement ce que le plafond de
    // consultation et l'anonymat symétrique existent pour empêcher.
    $moi = detenteurInventaire();
    $autre = detenteurInventaire('+2250700000002');

    bienInventaire($moi, '1M8GDM9AXKP042788');
    bienInventaire($autre, 'JH4KA7561PC008269');

    Sanctum::actingAs($moi);

    $reponse = $this->getJson('/api/v1/assets')->assertOk();

    expect($reponse->json('assets'))->toHaveCount(1)
        ->and($reponse->json('assets.0.identifier'))->toBe('1M8GDM9AXKP042788');
});

it('exige un compte', function (): void {
    // Règle métier absolue n° 2 : la consultation seule est ouverte, et elle ne
    // passe pas par ici.
    $this->getJson('/api/v1/assets')->assertStatus(401);
});

it('exclut les enregistrements archivés', function (): void {
    // Un bien cédé appartient à quelqu'un d'autre. Le laisser dans la liste de
    // l'ancien détenteur lui laisserait croire qu'il peut encore le déclarer
    // volé — et le refus du serveur lui serait incompréhensible.
    $moi = detenteurInventaire();

    bienInventaire($moi, '1M8GDM9AXKP042788');
    bienInventaire($moi, 'JH4KA7561PC008269', actif: false);

    Sanctum::actingAs($moi);

    expect($this->getJson('/api/v1/assets')->json('assets'))->toHaveCount(1);
});

it('remonte en tête ce qui alarme', function (): void {
    // Un bien volé enfoui sous onze autres dans une liste triée par date ne se
    // voit pas — et c'est précisément celui sur lequel il reste à agir.
    $moi = detenteurInventaire();

    $ancien = bienInventaire($moi, '1M8GDM9AXKP042788', LifeStatus::Stolen);
    $ancien->forceFill(['registered_at' => now()->subYears(3)])->save();

    bienInventaire($moi, 'JH4KA7561PC008269');

    Sanctum::actingAs($moi);

    expect($this->getJson('/api/v1/assets')->json('assets.0.life_status.code'))
        ->toBe(LifeStatus::Stolen->value);
});

it('ne nomme jamais personne', function (): void {
    // Même dans sa propre vue, le détenteur n'est désigné nulle part : il n'y
    // en a aucun besoin — c'est le porteur du jeton — et une identité qui ne
    // circule pas ne fuit pas.
    $moi = detenteurInventaire();
    bienInventaire($moi);

    Sanctum::actingAs($moi);

    $bien = $this->getJson('/api/v1/assets')->json('assets.0');

    expect($bien)->not->toHaveKey('owner_user_id')
        ->and($bien)->not->toHaveKey('owner')
        ->and(json_encode($bien))->not->toContain($moi->phone);
});

it('rend l\'identifiant interne, sans quoi aucune action n\'est possible', function (): void {
    // Le rendre ne fabrique aucun moyen de balayage : le serveur répond 404, et
    // non 403, sur le bien d'autrui.
    $moi = detenteurInventaire();
    $bien = bienInventaire($moi);

    Sanctum::actingAs($moi);

    expect($this->getJson('/api/v1/assets')->json('assets.0.id'))->toBe($bien->id);
});

it('reste lisible pendant une maintenance en lecture seule', function (): void {
    // ST-0904 : « lecture seule » doit vouloir dire ce que son nom annonce.
    // Refuser à quelqu'un l'accès à son propre inventaire ne protège rien —
    // cette requête n'écrit pas — et fait passer une indisponibilité partielle
    // pour une panne.
    $moi = detenteurInventaire();
    bienInventaire($moi);

    Sanctum::actingAs($moi);
    app(PlatformState::class)->setReadOnly(true, null, 'Migration de schéma');

    expect($this->getJson('/api/v1/assets')->assertOk()->json('assets'))->toHaveCount(1);
});

it('accompagne l\'inventaire du quota restant', function (): void {
    // L'utilisateur voit ce qu'il lui reste AVANT d'ouvrir un formulaire,
    // plutôt que de l'apprendre au refus après quatre-vingt-dix secondes.
    $moi = detenteurInventaire();
    bienInventaire($moi);

    Sanctum::actingAs($moi);

    expect($this->getJson('/api/v1/assets')->json('quota'))->toBeArray();
});

it('rend à l\'acheteur le transfert qui l\'attend', function (): void {
    // SANS CELA, LA CONFIRMATION EST INATTEIGNABLE : l'invitation qu'il reçoit
    // est un code par SMS, qui ne porte aucun numéro de transfert.
    $cedant = detenteurInventaire();
    $bien = bienInventaire($cedant);

    Transfer::create([
        'asset_id' => $bien->id,
        'from_user_id' => $cedant->id,
        'to_phone' => '+2250700000002',
        'status' => TransferStatus::Initiated,
        'previous_life_status' => LifeStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    // L'acheteur n'avait pas de compte au moment du transfert : c'est sa
    // première connexion qui le fait apparaître ici, par son NUMÉRO.
    $acheteur = detenteurInventaire('+2250700000002');
    Sanctum::actingAs($acheteur);

    $transferts = $this->getJson('/api/v1/transfers')->assertOk()->json('transfers');

    expect($transferts)->toHaveCount(1)
        ->and($transferts[0]['role'])->toBe('buyer');
});

it('nomme le camp de chacun plutôt que de le laisser deviner', function (): void {
    // Une erreur de camp ferait annuler un transfert qu'on croyait accepter.
    $cedant = detenteurInventaire();
    $bien = bienInventaire($cedant);

    Transfer::create([
        'asset_id' => $bien->id,
        'from_user_id' => $cedant->id,
        'to_phone' => '+2250700000002',
        'status' => TransferStatus::Initiated,
        'previous_life_status' => LifeStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    Sanctum::actingAs($cedant);

    expect($this->getJson('/api/v1/transfers')->json('transfers.0.role'))->toBe('seller');
});

it('ne livre pas le numéro complet du bien à un transfert entrant', function (): void {
    // Un transfert part vers un NUMÉRO saisi à la main : un chiffre de travers
    // et la fiche complète d'un véhicule arriverait chez un inconnu, qui
    // n'aurait plus qu'à la réclamer.
    $cedant = detenteurInventaire();
    $bien = bienInventaire($cedant, '1M8GDM9AXKP042788');

    Transfer::create([
        'asset_id' => $bien->id,
        'from_user_id' => $cedant->id,
        'to_phone' => '+2250700000002',
        'status' => TransferStatus::Initiated,
        'previous_life_status' => LifeStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    Sanctum::actingAs(detenteurInventaire('+2250700000002'));

    $transferts = $this->getJson('/api/v1/transfers')->json('transfers');

    expect(json_encode($transferts))->not->toContain('1M8GDM9AXKP042788')
        ->and($transferts[0]['asset']['public_ref'])->toBe($bien->public_ref);
});

it('tait les transferts clos et expirés', function (): void {
    // Ils appartiennent à l'historique du bien, pas à une liste de gestes en
    // attente : les y laisser ferait chercher une action qui n'existe plus.
    $cedant = detenteurInventaire();
    $bien = bienInventaire($cedant);

    Transfer::create([
        'asset_id' => $bien->id,
        'from_user_id' => $cedant->id,
        'to_phone' => '+2250700000002',
        'status' => TransferStatus::Cancelled,
        'previous_life_status' => LifeStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    Transfer::create([
        'asset_id' => $bien->id,
        'from_user_id' => $cedant->id,
        'to_phone' => '+2250700000003',
        'status' => TransferStatus::Initiated,
        'previous_life_status' => LifeStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    Sanctum::actingAs($cedant);

    expect($this->getJson('/api/v1/transfers')->json('transfers'))->toBeEmpty();
});

it('ouvre une réclamation par la référence publique', function (): void {
    // LE SEUL CHEMIN QU'UNE VICTIME PUISSE EMPRUNTER : elle ne connaît pas
    // l'identifiant interne du bien qu'on lui a pris, et la consultation
    // publique le tait — précisément pour qu'on ne puisse pas balayer le
    // registre. Sans cette route, le recours de l'EP-05 n'était atteignable
    // par personne.
    $detenteur = detenteurInventaire();
    $bien = bienInventaire($detenteur);

    Sanctum::actingAs(detenteurInventaire('+2250700000002'));

    $this->postJson('/api/v1/claims', ['public_ref' => $bien->public_ref])
        ->assertStatus(201)
        ->assertJsonPath('claim.status', 'draft');
});

it('ne réclame pas un enregistrement archivé', function (): void {
    // Un bien archivé appartient au passé : le contester reviendrait à
    // contester une ligne d'historique, pendant que l'enregistrement actif —
    // le seul qui protège quelqu'un — resterait intact.
    $detenteur = detenteurInventaire();
    $bien = bienInventaire($detenteur, actif: false);

    Sanctum::actingAs(detenteurInventaire('+2250700000002'));

    $this->postJson('/api/v1/claims', ['public_ref' => $bien->public_ref])
        ->assertStatus(404);
});

it('annonce une route de réclamation qui existe vraiment', function (): void {
    // Elle annonçait un `GET` qui n'a jamais été servi : un client qui l'aurait
    // suivi aurait mené la victime vers un 404, au moment précis où on lui
    // apprend que son bien est au nom de quelqu'un d'autre.
    $controleur = file_get_contents(app_path('Http/Controllers/Api/V1/AssetController.php'));

    expect($controleur)->not->toContain('claims?public_ref');

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->methods()[0].' /'.$route->uri());

    expect($routes)->toContain('POST /api/v1/claims');
});

it('ne rend pas les transferts des autres', function (): void {
    $cedant = detenteurInventaire();
    $bien = bienInventaire($cedant);

    Transfer::create([
        'asset_id' => $bien->id,
        'from_user_id' => $cedant->id,
        'to_phone' => '+2250700000002',
        'status' => TransferStatus::Initiated,
        'previous_life_status' => LifeStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    Sanctum::actingAs(detenteurInventaire('+2250700000009'));

    expect($this->getJson('/api/v1/transfers')->json('transfers'))->toBeEmpty();
});
