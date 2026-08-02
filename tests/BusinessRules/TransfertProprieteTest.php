<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\TransferStatus;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Otp\OtpSender;
use App\Services\OtpService;
use App\Services\StatusTransitionService;
use App\Services\TransferService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EP-06, ST-0601 à ST-0603. Vérifie surtout la règle métier absolue n° 3 :
 * l'archivage de l'ancien enregistrement et la création du nouveau ont lieu
 * dans la MÊME transaction, si bien qu'un identifiant n'est jamais sans
 * enregistrement actif — ni avec deux.
 *
 * N'utilise pas RefreshDatabase : le transfert écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('transfers')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerTransferts();

    $this->sender = new CapteurCodes;
    $this->app->instance(OtpSender::class, $this->sender);
    $this->transferts = app(TransferService::class);
});
afterEach(fn () => nettoyerTransferts());

function nettoyerTransferts(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'transfers', 'asset_status_history',
        'assets', 'otp_codes', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function vendeur(string $telephone = '+2250700000001', bool $kyc = true): User
{
    $utilisateur = User::create(['phone' => $telephone]);

    if ($kyc) {
        $utilisateur->forceFill([
            'kyc_status' => KycStatus::Verified->value,
            'kyc_verified_at' => now()->subMonth(),
        ])->save();
    }

    return $utilisateur;
}

function bienCessible(User $proprietaire, ?LifeStatus $statut = null): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Documented,
        'life_status' => $statut ?? LifeStatus::Active,
        'registered_at' => now()->subYear(),
    ]);
}

/** Mène un transfert jusqu'au bout et rend l'acheteur. */
function transfererA(Transfer $transfert, User $vendeur, string $telephoneAcheteur): User
{
    $acheteur = User::create(['phone' => $telephoneAcheteur]);

    test()->transferts->confirmByBuyer($transfert, $acheteur, test()->sender->pour($telephoneAcheteur));

    app(OtpService::class)->request($vendeur->phone, OtpPurpose::Transfer);
    test()->transferts->confirmBySeller(
        $transfert->fresh() ?? $transfert,
        $vendeur,
        test()->sender->pour($vendeur->phone),
    );

    return $acheteur;
}

it('bascule le bien en Transfert en cours à l\'initiation', function (): void {
    $cedant = vendeur();
    $bien = bienCessible($cedant);

    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Transferring)
        ->and($transfert->status)->toBe(TransferStatus::Initiated)
        ->and($transfert->previous_life_status)->toBe(LifeStatus::Active);
});

it('envoie son code à l\'acheteur, qui n\'a pas besoin de compte', function (): void {
    // Exiger que l'acheteur soit déjà inscrit ferait échouer la vente au
    // marché, là où elle se conclut.
    $cedant = vendeur();
    $transfert = $this->transferts->initiate(bienCessible($cedant), $cedant, '0700000002');

    expect($this->sender->pour('+2250700000002'))->toMatch('/^\d{6}$/')
        ->and($transfert->to_user_id)->toBeNull()
        ->and(User::count())->toBe(1);
});

it('refuse le transfert par qui n\'est pas le détenteur', function (): void {
    $cedant = vendeur();
    $bien = bienCessible($cedant);

    expect(fn () => $this->transferts->initiate($bien, vendeur('+2250700000003'), '0700000002'))
        ->toThrow(DomainException::class);
});

it('exige une identité vérifiée du vendeur', function (): void {
    // Un transfert opéré depuis un compte non vérifié serait le moyen le plus
    // simple de blanchir un bien volé.
    $cedant = vendeur(kyc: false);
    $bien = bienCessible($cedant);

    expect(fn () => $this->transferts->initiate($bien, $cedant, '0700000002'))
        ->toThrow(DomainException::class);
});

it('refuse un second transfert sur un bien déjà en cours de cession', function (): void {
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $this->transferts->initiate($bien, $cedant, '0700000002');

    expect(fn () => $this->transferts->initiate($bien->fresh() ?? $bien, $cedant, '0700000003'))
        ->toThrow(DomainException::class);
});

it('refuse un transfert vers son propre numéro', function (): void {
    $cedant = vendeur();

    expect(fn () => $this->transferts->initiate(bienCessible($cedant), $cedant, $cedant->phone))
        ->toThrow(DomainException::class);
});

it('ne finalise pas sur une seule confirmation', function (): void {
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    $acheteur = User::create(['phone' => '+2250700000002']);
    $this->transferts->confirmByBuyer($transfert, $acheteur, $this->sender->pour('+2250700000002'));

    expect($transfert->fresh()?->status)->toBe(TransferStatus::BuyerConfirmed)
        ->and($bien->fresh()?->life_status)->toBe(LifeStatus::Transferring)
        ->and(Asset::count())->toBe(1);
});

it('archive l\'ancien et crée le nouveau sans jamais laisser deux actifs', function (): void {
    // LE test de la règle métier absolue n° 3.
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    $acheteur = transfererA($transfert, $cedant, '+2250700000002');

    $actifs = Asset::where('identifier_normalized', '1M8GDM9AXKP042788')->whereNotNull('active_flag')->get();

    expect($actifs)->toHaveCount(1)
        ->and($actifs->first()?->owner_user_id)->toBe($acheteur->id)
        ->and($bien->fresh()?->active_flag)->toBeNull()
        ->and(Asset::count())->toBe(2);
});

it('fait repartir le nouvel enregistrement en Déclaré non vérifié', function (): void {
    // Une carte grise au nom du vendeur ne prouve rien sur l'acheteur :
    // hériter du niveau vendrait avec le bien une confiance non vérifiée.
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    transfererA($transfert, $cedant, '+2250700000002');

    $nouveau = Asset::whereNotNull('active_flag')->sole();

    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented)
        ->and($nouveau->trust_level)->toBe(TrustLevel::Declared)
        ->and($nouveau->life_status)->toBe(LifeStatus::Active)
        ->and($nouveau->provisional_until)->toBeNull();
});

it('relie le nouvel enregistrement au précédent', function (): void {
    // Sans ce lien, la chaîne des détenteurs (ST-0603) serait
    // irreconstituable, alors qu'elle fonde le rapport détaillé payant.
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    transfererA($transfert, $cedant, '+2250700000002');

    $nouveau = Asset::whereNotNull('active_flag')->sole();

    expect($nouveau->previous_asset_id)->toBe($bien->id)
        ->and($this->transferts->holderChain($nouveau))->toHaveCount(2);
});

it('journalise la finalisation avec les deux enregistrements', function (): void {
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    transfererA($transfert, $cedant, '+2250700000002');

    $entree = AuditLog::where('action', 'transfer.completed')->sole();
    $nouveau = Asset::whereNotNull('active_flag')->sole();

    expect($entree->payload['archived_asset_id'] ?? null)->toBe($bien->id)
        ->and($entree->payload['created_asset_id'] ?? null)->toBe($nouveau->id);
});

it('n\'écrit jamais le numéro de l\'acheteur dans la chaîne d\'audit', function (): void {
    // Il désigne quelqu'un qui n'a peut-être pas encore de compte, et
    // audit_log est inaltérable.
    $cedant = vendeur();
    $this->transferts->initiate(bienCessible($cedant), $cedant, '0700000002');

    $entree = AuditLog::where('action', 'transfer.initiated')->sole();

    expect(json_encode($entree->payload))->not->toContain('2250700000002');
});

it('ramène le bien à son statut antérieur en cas d\'annulation', function (): void {
    // Un véhicule de loueur doit revenir « En location », pas « Actif » :
    // sinon il redeviendrait vendable sans avertissement.
    $cedant = vendeur();
    $bien = bienCessible($cedant, LifeStatus::Rented);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    $this->transferts->cancel($transfert, $cedant);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Rented)
        ->and($transfert->fresh()?->status)->toBe(TransferStatus::Cancelled)
        ->and(Asset::count())->toBe(1);
});

it('ramène le bien à son statut antérieur à l\'expiration', function (): void {
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    $this->transferts->expire($transfert);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active)
        ->and($transfert->fresh()?->status)->toBe(TransferStatus::Expired);
});

it('refuse toute confirmation après expiration', function (): void {
    $cedant = vendeur();
    $transfert = $this->transferts->initiate(bienCessible($cedant), $cedant, '0700000002');

    $this->travel(8)->days();

    $acheteur = User::create(['phone' => '+2250700000002']);

    expect(fn () => $this->transferts->confirmByBuyer($transfert->fresh() ?? $transfert, $acheteur, '000000'))
        ->toThrow(DomainException::class);
});

it('refuse la confirmation par un tiers', function (): void {
    $cedant = vendeur();
    $transfert = $this->transferts->initiate(bienCessible($cedant), $cedant, '0700000002');

    $tiers = User::create(['phone' => '+2250700000009']);

    expect(fn () => $this->transferts->confirmByBuyer($transfert, $tiers, '000000'))
        ->toThrow(DomainException::class);
});

it('refuse l\'annulation par quelqu\'un d\'autre que le vendeur', function (): void {
    $cedant = vendeur();
    $transfert = $this->transferts->initiate(bienCessible($cedant), $cedant, '0700000002');

    expect(fn () => $this->transferts->cancel($transfert, vendeur('+2250700000009')))
        ->toThrow(DomainException::class);
});

it('laisse le bien volé pendant le transfert plutôt que de le ramener en arrière', function (): void {
    // Une déclaration de vol pendant la cession prime : la ramener au statut
    // antérieur effacerait l'alerte.
    $cedant = vendeur();
    $bien = bienCessible($cedant);
    $transfert = $this->transferts->initiate($bien, $cedant, '0700000002');

    app(StatusTransitionService::class)->transitionTo(
        $bien->fresh() ?? $bien,
        LifeStatus::Stolen,
        TriggerType::Owner,
        $cedant->id,
    );

    $this->transferts->expire($transfert);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

/** Capte les codes émis, par destination. */
class CapteurCodes implements OtpSender
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
