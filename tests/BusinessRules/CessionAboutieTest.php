<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\OtpPurpose;
use App\Enums\TransferStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Notification;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Otp\OtpSender;
use App\Services\TransferService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * CE QUI MANQUAIT POUR QU'UNE CESSION ABOUTISSE (ST-0601).
 *
 * Le parcours s'écrivait de bout en bout et ne se terminait pour personne. Trois
 * ruptures, chacune suffisante à elle seule pour qu'un bien payé et emporté
 * reste enregistré au nom du vendeur :
 *
 * 1. LE CODE ÉTAIT ÉMIS AILLEURS QUE LÀ OÙ IL EST VÉRIFIÉ. L'application
 *    demandait un code sur la coordonnée DU COMPTE qui se présente ; le serveur
 *    le cherchait sur celle DU TRANSFERT. Dès qu'une adresse était donnée — le
 *    cas recommandé, et le seul par lequel l'acheteur est réellement prévenu —
 *    les deux différaient, et aucun code saisi n'était jamais reconnu.
 *
 * 2. LE LIEN VIT SEPT JOURS, LE CODE CINQ MINUTES, ET LA PAGE N'EN REDEMANDAIT
 *    PAS. L'acheteur qui ouvrait son courriel un quart d'heure plus tard lisait
 *    « Ce code a expiré. Demandez-en un nouveau » — sans qu'aucun bouton, nulle
 *    part, ne permette de le faire. Le seul chemin proposé était l'application,
 *    que cette page existe précisément pour éviter d'exiger.
 *
 * 3. UN BIEN NON CESSIBLE LAISSAIT UN TRANSFERT ORPHELIN. Le transfert était
 *    écrit et validé AVANT que la matrice des statuts ne refuse la bascule : le
 *    refus arrivait trop tard, la ligne restait, et le bien devenait
 *    incessible pendant sept jours par un transfert qui n'avait jamais existé.
 *
 * Et une quatrième, plus discrète : PERSONNE N'ÉTAIT PRÉVENU. Les types
 * `TransferInvitation` et `TransferCompleted` étaient définis depuis EP-10 et
 * aucun code ne les émettait — le vendeur ne savait pas que son tour était venu,
 * ni, plus tard, que son bien avait changé de mains.
 *
 * N'utilise pas RefreshDatabase : le transfert écrit dans la chaîne d'audit,
 * qui refuse de s'exécuter dans une transaction englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('transfers')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerCessions();

    $this->codes = new CapteurCession;
    $this->app->instance(OtpSender::class, $this->codes);
    $this->cessions = app(TransferService::class);

    Mail::fake();
});
afterEach(fn () => nettoyerCessions());

function nettoyerCessions(): void
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

/** Retient les codes émis, indexés par la destination RÉELLE de livraison. */
class CapteurCession implements OtpSender
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

function cedantCessible(): User
{
    $utilisateur = User::create([
        'phone' => '+2250702'.random_int(100000, 999999),
        'email' => 'vendeur'.random_int(1000, 9999).'@exemple.ci',
    ]);

    $utilisateur->forceFill([
        'kyc_status' => KycStatus::Verified->value,
        'kyc_verified_at' => now()->subMonth(),
    ])->save();

    return $utilisateur;
}

function bienDeLaCessionAboutie(User $proprietaire, LifeStatus $statut = LifeStatus::Active): Asset
{
    $vin = '1M8GDM9AXKP05'.random_int(1000, 9999);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $vin,
        'identifier_normalized' => $vin,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Honda CG'],
        'trust_level' => TrustLevel::Documented,
        'life_status' => $statut,
        'registered_at' => now()->subYear(),
    ]);
}

/**
 * Ouvre une cession vers un numéro ET une adresse — le cas recommandé, et
 * celui où les deux coordonnées diffèrent.
 *
 * @return array{0: Transfer, 1: User, 2: string}
 */
function cessionComplete(): array
{
    $vendeur = cedantCessible();
    $numero = '+2250798'.random_int(100000, 999999);
    $adresse = 'acheteur'.random_int(1000, 9999).'@exemple.ci';

    $transfert = test()->cessions->initiate(bienDeLaCessionAboutie($vendeur), $vendeur, $numero, $adresse);

    return [$transfert, $vendeur, $adresse];
}

it('DONNE À L\'ACHETEUR UN CODE QUE LA VÉRIFICATION RECONNAÎT', function (): void {
    // LE DÉFAUT D'ORIGINE. L'application demandait le code sur la coordonnée du
    // COMPTE, le serveur le cherchait sur celle du TRANSFERT. Un acheteur
    // inscrit par téléphone, prévenu par adresse, ne pouvait JAMAIS confirmer :
    // aucun code saisi n'était reconnu, et la cession expirait à sept jours.
    [$transfert, , $adresse] = cessionComplete();

    // Compte inscrit par NUMÉRO, avec une adresse personnelle DIFFÉRENTE de
    // celle vers laquelle le vendeur a ouvert la cession.
    $acheteur = User::create([
        'phone' => $transfert->to_phone,
        'email' => 'perso'.random_int(1000, 9999).'@exemple.ci',
    ]);

    Sanctum::actingAs($acheteur);

    $reponse = test()->postJson('/api/v1/transfers/'.$transfert->id.'/code')->assertOk();

    // Le code part là où la cession a été ouverte, pas sur l'adresse du compte.
    $code = test()->codes->pour($adresse);

    expect($code)->not->toBe('')
        ->and($reponse->json('sent_to'))->toBeString()
        // Celui de l'ouverture est encore valable : on le rappelle, on ne le
        // remplace pas — et surtout on ne refuse pas la demande.
        ->and($reponse->json('fresh'))->toBeFalse();

    test()->postJson('/api/v1/transfers/'.$transfert->id.'/confirm', [
        'code' => $code,
        'role' => 'buyer',
    ])->assertOk();

    expect($transfert->fresh()?->status)->toBe(TransferStatus::BuyerConfirmed);
});

it('NE DIT PAS AU VENDEUR OÙ PART LE CODE DE L\'ACHETEUR', function (): void {
    // Règle métier absolue n° 4 : le vendeur ne doit rien apprendre de son
    // acheteur. Son propre code part chez LUI, et c'est cela qu'on lui dit.
    [$transfert, $vendeur, $adresse] = cessionComplete();

    $codeAcheteur = test()->codes->pour($adresse);

    Sanctum::actingAs($vendeur);

    $reponse = test()->postJson('/api/v1/transfers/'.$transfert->id.'/code')->assertOk();

    expect($reponse->json('sent_to'))->not->toContain(explode('@', $adresse)[0])
        // Rien n'est parti chez l'acheteur : son code est celui d'avant.
        ->and(test()->codes->pour($adresse))->toBe($codeAcheteur);
});

it('DONNE AU VENDEUR UN CODE QUE SA PROPRE CONFIRMATION RECONNAÎT', function (): void {
    // Rien n'émettait le code du vendeur : `confirmBySeller` le cherchait sur
    // la coordonnée de son compte, et seule une demande de connexion — un autre
    // motif — pouvait en produire un par accident.
    [$transfert, $vendeur] = cessionComplete();

    Sanctum::actingAs($vendeur);
    test()->postJson('/api/v1/transfers/'.$transfert->id.'/code')->assertOk();

    $code = test()->codes->pour((string) $vendeur->email);

    expect($code)->not->toBe('');

    test()->postJson('/api/v1/transfers/'.$transfert->id.'/confirm', [
        'code' => $code,
        'role' => 'seller',
    ])->assertOk();

    expect($transfert->fresh()?->seller_otp_at)->not->toBeNull();
});

it('REFUSE D\'ÉMETTRE UN CODE POUR UNE CESSION QUI NE NOUS CONCERNE PAS', function (): void {
    // Sans ce contrôle, la route dirait qu'un transfert existe à cet
    // identifiant — et permettrait de faire partir des codes en rafale chez un
    // acheteur qu'on ne connaît pas.
    [$transfert] = cessionComplete();

    Sanctum::actingAs(User::create(['phone' => '+2250777'.random_int(100000, 999999)]));

    test()->postJson('/api/v1/transfers/'.$transfert->id.'/code')->assertNotFound();
});

it('REDONNE UN CODE DEPUIS LA PAGE WEB QUAND LE PREMIER A EXPIRÉ', function (): void {
    // LE LIEN VIT SEPT JOURS, LE CODE CINQ MINUTES. L'acheteur qui ouvre son
    // courriel un quart d'heure plus tard n'avait plus aucun recours : la page
    // lui disait d'en demander un depuis l'application, qu'elle existe
    // justement pour ne pas exiger.
    [$transfert, , $adresse] = cessionComplete();

    $premier = test()->codes->pour($adresse);

    // Le code expire ; le lien, lui, est encore bon pour six jours.
    $this->travel(20)->minutes();

    test()->post('/cession/'.$transfert->invite_token, ['code' => $premier])
        ->assertOk()
        ->assertSee('expiré', false);

    test()->post('/cession/'.$transfert->invite_token.'/code')->assertOk();

    $second = test()->codes->pour($adresse);

    expect($second)->not->toBe('')->and($second)->not->toBe($premier);

    test()->post('/cession/'.$transfert->invite_token, ['code' => $second])->assertOk();

    expect($transfert->fresh()?->status)->toBe(TransferStatus::BuyerConfirmed);
});

it('PROPOSE LE RENVOI SUR LA PAGE ELLE-MÊME', function (): void {
    // Une capacité qu'aucun écran n'atteint n'existe pas : c'est le défaut que
    // ce parcours répète depuis le début.
    [$transfert] = cessionComplete();

    test()->get('/cession/'.$transfert->invite_token)
        ->assertOk()
        ->assertSee('RECEVOIR UN NOUVEAU CODE', false);
});

it('REFUSE UN BIEN NON CESSIBLE SANS LAISSER DE TRANSFERT DERRIÈRE LUI', function (): void {
    // Le transfert était écrit AVANT que la matrice ne refuse la bascule : la
    // ligne survivait au refus, et le bien devenait incessible pendant sept
    // jours par un transfert qui n'avait jamais commencé.
    $vendeur = cedantCessible();
    $bien = bienDeLaCessionAboutie($vendeur, LifeStatus::Provisional);

    expect(fn () => test()->cessions->initiate($bien, $vendeur, '+2250798123456', 'a@exemple.ci'))
        ->toThrow(DomainException::class);

    expect(Transfer::query()->where('asset_id', $bien->id)->count())->toBe(0)
        ->and($bien->fresh()?->life_status)->toBe(LifeStatus::Provisional);
});

it('NE MONTRE JAMAIS UN CODE DE STATUT DANS UN REFUS DE CESSION', function (): void {
    // CT-04 : les codes de la matrice ne sortent pas vers l'interface.
    $vendeur = cedantCessible();
    $bien = bienDeLaCessionAboutie($vendeur, LifeStatus::Provisional);

    try {
        test()->cessions->initiate($bien, $vendeur, '+2250798123456');
        $message = '';
    } catch (DomainException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('')
        ->and($message)->not->toContain('V-PRV')
        ->and($message)->not->toContain('V-VTE');
});

it('PRÉVIENT LE VENDEUR QUE SON TOUR EST VENU', function (): void {
    // L'acheteur accepte, et rien ne le disait au vendeur : il devait ouvrir
    // l'application au hasard pour découvrir qu'on l'attendait. Sept jours plus
    // tard, la cession expirait faute d'un geste que personne n'avait réclamé.
    [$transfert, , $adresse] = cessionComplete();

    test()->post('/cession/'.$transfert->invite_token, [
        'code' => test()->codes->pour($adresse),
    ])->assertOk();

    expect(Notification::query()
        ->where('user_id', $transfert->from_user_id)
        ->where('type', NotificationType::TransferInvitation->value)
        ->exists())->toBeTrue();
});

it('PRÉVIENT LES DEUX PARTIES QUAND LA CESSION EST ACHEVÉE', function (): void {
    // Le bien quitte un inventaire et entre dans un autre : les deux côtés
    // doivent l'apprendre autrement qu'en le constatant.
    [$transfert, $vendeur, $adresse] = cessionComplete();

    test()->post('/cession/'.$transfert->invite_token, [
        'code' => test()->codes->pour($adresse),
    ])->assertOk();

    Sanctum::actingAs($vendeur);
    test()->postJson('/api/v1/transfers/'.$transfert->id.'/code')->assertOk();
    test()->postJson('/api/v1/transfers/'.$transfert->id.'/confirm', [
        'code' => test()->codes->pour((string) $vendeur->email),
        'role' => 'seller',
    ])->assertOk();

    $acheve = $transfert->fresh();
    $acheteur = User::query()->where('email', $adresse)->first();

    expect($acheve?->status)->toBe(TransferStatus::Completed)
        ->and(Notification::query()
            ->where('user_id', $vendeur->id)
            ->where('type', NotificationType::TransferCompleted->value)
            ->exists())->toBeTrue()
        ->and(Notification::query()
            ->where('user_id', $acheteur?->id)
            ->where('type', NotificationType::TransferCompleted->value)
            ->exists())->toBeTrue();
});

it('N\'OUVRE AUCUN COMPTE SUR UN CODE REFUSÉ', function (): void {
    // Le compte était créé AVANT la vérification : une saisie fautive laissait
    // derrière elle un compte que personne n'avait ouvert, et sur une adresse
    // dont la maîtrise n'était pas prouvée.
    [$transfert, , $adresse] = cessionComplete();

    test()->post('/cession/'.$transfert->invite_token, ['code' => '000000'])->assertOk();

    expect(User::query()->where('email', $adresse)->exists())->toBeFalse();
});
