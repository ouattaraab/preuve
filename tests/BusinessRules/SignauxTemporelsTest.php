<?php

declare(strict_types=1);

use App\Enums\AgeBracket;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ST-0404 : l'acheteur doit pouvoir jauger la confiance sur des signaux
 * NON ANTIDATABLES — sans jamais apprendre qui est le déclarant.
 */
function bienAvecAnciennete(string $bienCree, string $compteCree): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    DB::table('users')->where('id', $proprietaire->id)->update(['created_at' => $compteCree]);

    $identifiant = 'AGE'.random_int(100000, 999999);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $identifiant,
        'identifier_normalized' => $identifiant,
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => $bienCree,
    ]);
}

it('classe l\'ancienneté par tranches', function (): void {
    expect(AgeBracket::fromDate(now()->subDays(2)))->toBe(AgeBracket::ThisWeek)
        ->and(AgeBracket::fromDate(now()->subDays(15)))->toBe(AgeBracket::ThisMonth)
        ->and(AgeBracket::fromDate(now()->subMonths(4)))->toBe(AgeBracket::Months)
        ->and(AgeBracket::fromDate(now()->subMonths(15)))->toBe(AgeBracket::Year)
        ->and(AgeBracket::fromDate(now()->subYears(3)))->toBe(AgeBracket::Years);
});

it('expose l\'ancienneté sur le verdict public', function (): void {
    $bien = bienAvecAnciennete(
        now()->subYears(2)->format('Y-m-d H:i:s'),
        now()->subYears(3)->format('Y-m-d H:i:s'),
    );

    $reponse = $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)->assertOk();

    expect($reponse->json('asset.signals.registration_age.bracket'))->toBe('years')
        ->and($reponse->json('asset.signals.holder_account_age.bracket'))->toBe('years')
        ->and($reponse->json('asset.signals.notice'))->toBeNull();
});

it('signale un enregistrement et un compte très récents', function (): void {
    // Le profil exact d'un vol récent qu'on cherche à revendre — sans que ce
    // soit une accusation : un bien acheté hier produit le même signal.
    $bien = bienAvecAnciennete(
        now()->subDays(2)->format('Y-m-d H:i:s'),
        now()->subDays(2)->format('Y-m-d H:i:s'),
    );

    $reponse = $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)->assertOk();

    expect($reponse->json('asset.signals.registration_age.attention'))->toBeTrue()
        ->and($reponse->json('asset.signals.holder_account_age.attention'))->toBeTrue()
        ->and($reponse->json('asset.signals.notice'))->toContain('demandez au vendeur');
});

it('reste factuel, sans porter de jugement', function (): void {
    // La plateforme informe, elle ne juge pas : c'est à l'acheteur de conclure,
    // avec le vendeur en face de lui.
    $bien = bienAvecAnciennete(
        now()->subDays(1)->format('Y-m-d H:i:s'),
        now()->subDays(1)->format('Y-m-d H:i:s'),
    );

    $avis = (string) $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)
        ->assertOk()
        ->json('asset.signals.notice');

    expect($avis)->not->toContain('suspect')
        ->and($avis)->not->toContain('fraude')
        ->and($avis)->not->toContain('volé')
        ->and($avis)->toContain('n\'est pas anormal');
});

it('ne donne jamais la date exacte de création du compte', function (): void {
    // Une date exacte, recoupée avec ce qu'un acheteur sait par ailleurs,
    // aiderait à identifier le déclarant.
    $bien = bienAvecAnciennete(
        now()->subMonths(6)->format('Y-m-d H:i:s'),
        '2019-03-17 08:42:11',
    );

    $contenu = $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)->assertOk()->content();

    expect($contenu)->not->toContain('2019-03-17')
        ->and($contenu)->not->toContain('08:42');
});

it('n\'expose toujours aucune identité', function (): void {
    $bien = bienAvecAnciennete(
        now()->subMonths(6)->format('Y-m-d H:i:s'),
        now()->subYears(2)->format('Y-m-d H:i:s'),
    );

    $proprietaire = $bien->owner;
    $proprietaire?->forceFill(['full_name' => 'Awa Koné'])->save();

    $contenu = $this->getJson('/api/v1/lookup/'.$bien->identifier_normalized)->assertOk()->content();

    expect($contenu)->not->toContain('Awa Koné')
        ->and($contenu)->not->toContain((string) $proprietaire?->phone);
});

it('n\'accepte aucune date d\'enregistrement soufflée par l\'API', function (): void {
    // Un signal que le déclarant pourrait antidater ne vaudrait rien : c'est
    // précisément le vendeur pressé qui aurait intérêt à se fabriquer de
    // l'ancienneté.
    $champsAcceptes = ['category', 'attributes', 'client_elapsed_ms'];
    $controleur = file_get_contents(app_path('Http/Controllers/Api/V1/AssetController.php'));

    // On surveille les lignes où la date VIENT DE LA REQUÊTE, et non toute
    // mention du nom de colonne : depuis que ce contrôleur rend aussi
    // l'inventaire du détenteur, il trie légitimement dessus. Interdire le mot
    // partout aurait fini par se contourner en renommant une variable, ce qui
    // n'aurait rien protégé du tout.
    $lignesSuspectes = array_filter(
        preg_split('/\R/', (string) $controleur) ?: [],
        fn (string $ligne): bool => str_contains($ligne, 'registered_at')
            && (str_contains($ligne, '$request') || str_contains($ligne, 'input(')),
    );

    expect($lignesSuspectes)->toBeEmpty()
        ->and($champsAcceptes)->not->toContain('registered_at');

    // La date est posée par le service, à partir de l'horloge du serveur : le
    // seul endroit où elle puisse l'être sans qu'un client ait son mot à dire.
    expect(file_get_contents(app_path('Services/AssetRegistrationService.php')))
        ->toContain('$registeredAt = now()')
        ->toContain("'registered_at' => \$registeredAt");
});
