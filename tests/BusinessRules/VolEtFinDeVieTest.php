<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\User;
use App\Services\AssetLifecycleService;
use App\Services\Otp\OtpSender;
use App\Services\OtpService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ST-0604 (vol en un geste), ST-0605 (levée par le déclarant), ST-0606 (fin de
 * vie).
 *
 * N'utilise pas RefreshDatabase : ces déclarations écrivent dans la chaîne
 * d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('transfers')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerCycleVie();

    $this->sender = new CapteurVol;
    $this->app->instance(OtpSender::class, $this->sender);
    $this->cycle = app(AssetLifecycleService::class);
});
afterEach(fn () => nettoyerCycleVie());

function nettoyerCycleVie(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'notifications', 'transfers', 'asset_status_history', 'assets', 'otp_codes', 'users'] as $t) {
        DB::statement("TRUNCATE TABLE {$t}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function detenteur(): User
{
    return User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
}

function bienVivant(User $proprietaire, ?LifeStatus $statut = null): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'telephone',
        'identifier_type' => 'imei',
        'identifier_raw' => '49015420323751'.random_int(0, 9),
        'identifier_normalized' => '49015420323751'.random_int(0, 9),
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut ?? LifeStatus::Active,
        'registered_at' => now()->subMonths(3),
    ]);
}

/**
 * Avance l'horloge avant chaque demande : OtpService impose un délai minimal
 * de 60 secondes entre deux envois vers un même numéro, pour protéger l'abonné
 * d'un pilonnage de SMS. Un scénario qui déclare puis lève un vol en enchaîne
 * deux.
 */
/**
 * Le code d'un compte, demandé là OÙ IL LE REÇOIT.
 *
 * `otpDestination()` et non `->phone` : un compte ouvert par adresse n'a pas de
 * numéro, et un harnais qui interrogerait la mauvaise colonne éprouverait autre
 * chose que la production.
 */
function codePour(User $utilisateur): string
{
    test()->travel(2)->minutes();

    $destination = $utilisateur->otpDestination() ?? '';

    app(OtpService::class)->request($destination, OtpPurpose::SensitiveAction);

    return test()->sender->pour($destination);
}

it('rend le bien invendable immédiatement, sur un seul code', function (): void {
    // Le détenteur vient de se faire dépouiller : chaque champ demandé est une
    // chance de plus que la déclaration n'aboutisse pas.
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);

    $this->cycle->declareStolen($bien, $proprietaire, codePour($proprietaire));

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen)
        ->and($bien->fresh()?->stolen_declared_at)->not->toBeNull()
        // La déclaration vaut sans récépissé : exiger une plainte préalable
        // rendrait le dispositif inutile là où il sert le plus.
        ->and($bien->fresh()?->stolen_consolidated)->toBeFalse();
});

it('refuse la déclaration sans code valide', function (): void {
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);

    expect(fn () => $this->cycle->declareStolen($bien, $proprietaire, '000000'))
        ->toThrow(Exception::class);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('refuse la déclaration par un tiers', function (): void {
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);
    $tiers = detenteur();

    expect(fn () => $this->cycle->declareStolen($bien, $tiers, codePour($tiers)))
        ->toThrow(DomainException::class);
});

it('permet au déclarant de lever son alerte', function (): void {
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);
    $this->cycle->declareStolen($bien, $proprietaire, codePour($proprietaire));

    $this->cycle->clearStolen($bien->fresh() ?? $bien, $proprietaire, codePour($proprietaire));

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('interdit à un tiers de lever une déclaration de vol', function (): void {
    // Sinon il suffirait de voler le bien puis de lever l'alerte pour le
    // revendre.
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);
    $this->cycle->declareStolen($bien, $proprietaire, codePour($proprietaire));

    $voleur = detenteur();

    expect(fn () => $this->cycle->clearStolen($bien->fresh() ?? $bien, $voleur, codePour($voleur)))
        ->toThrow(DomainException::class);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

it('garde la trace de l\'épisode après la levée', function (): void {
    // Un bien déclaré volé puis retrouvé deux fois n'a pas la même histoire
    // qu'un bien sans incident : le rapport détaillé doit pouvoir le dire.
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);
    $this->cycle->declareStolen($bien, $proprietaire, codePour($proprietaire));
    $this->cycle->clearStolen($bien->fresh() ?? $bien, $proprietaire, codePour($proprietaire));

    expect($bien->fresh()?->stolen_declared_at)->not->toBeNull()
        ->and(AssetStatusHistory::where('asset_id', $bien->id)->count())->toBe(2);
});

it('refuse de lever une alerte sur un bien qui n\'est pas volé', function (): void {
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);

    expect(fn () => $this->cycle->clearStolen($bien, $proprietaire, codePour($proprietaire)))
        ->toThrow(DomainException::class);
});

it('clôt le cycle de vie sans libérer l\'identifiant', function (): void {
    // Sans quoi déclarer une épave deviendrait le moyen de recycler un
    // identifiant volé sur un autre bien.
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire);

    $this->cycle->declareEndOfLife($bien, $proprietaire, codePour($proprietaire), 'Épave après accident');

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::EndOfLife)
        ->and($bien->fresh()?->active_flag)->toBe(1);
});

it('n\'autorise pas la fin de vie d\'un bien volé', function (): void {
    // Interdiction confirmée le 02/08/2026 : il faut d'abord lever le vol,
    // sinon la déclaration s'effacerait derrière une fin de vie.
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire, LifeStatus::Stolen);

    expect(fn () => $this->cycle->declareEndOfLife($bien, $proprietaire, codePour($proprietaire)))
        ->toThrow(Exception::class);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

it('peut déclarer volé un bien en location', function (): void {
    $proprietaire = detenteur();
    $bien = bienVivant($proprietaire, LifeStatus::Rented);

    $this->cycle->declareStolen($bien, $proprietaire, codePour($proprietaire));

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

/** Capte les codes émis, par destination. */
class CapteurVol implements OtpSender
{
    /** @var array<string, string> */
    public array $codes = [];

    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        $this->codes[$destination] = $code;
    }

    public function pour(string $destination): string
    {
        return $this->codes[$destination] ?? '';
    }
}

it('LAISSE UN COMPTE SANS NUMÉRO DÉCLARER LE VOL DE SON BIEN', function (): void {
    // Depuis qu'un compte s'ouvre par adresse, neuf endroits interrogeaient
    // `phone` en dur. Un titulaire sans numéro s'y heurtait en silence — et ne
    // pouvait pas signaler le vol de son propre bien, ce qui est la promesse
    // la plus urgente du produit.
    $sansNumero = User::create(['email' => 'awa@exemple.ci']);
    $sansNumero->forceFill([
        'kyc_status' => 'verified',
        'email_verified_at' => now(),
    ])->save();

    $bien = bienVivant($sansNumero);

    expect($sansNumero->otpDestination())->toBe('awa@exemple.ci');

    $this->cycle->declareStolen($bien, $sansNumero, codePour($sansNumero));

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

it('PRÉFÈRE LE NUMÉRO quand le compte a les deux', function (): void {
    // C'est lui que le SMS atteindra le jour où une passerelle sera branchée,
    // et changer de destination d'un jour à l'autre invaliderait les codes en
    // cours.
    $compte = User::create(['phone' => '+2250700123456', 'email' => 'awa@exemple.ci']);

    expect($compte->otpDestination())->toBe('+2250700123456');
});
