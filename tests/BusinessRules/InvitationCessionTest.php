<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\TransferStatus;
use App\Enums\TrustLevel;
use App\Mail\TransferInvitationMail;
use App\Models\Asset;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Otp\OtpSender;
use App\Services\OtpService;
use App\Services\TransferService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * ST-0601 : inviter l'acheteur, et le laisser accepter sans l'application.
 *
 * LE DÉFAUT QUE CES TESTS FERMENT. Une cession s'ouvrait vers un numéro, et le
 * code partait par SMS — vers une passerelle qui n'est pas branchée. Le vendeur
 * voyait son transfert « en cours », l'acheteur ne recevait rien, et sept jours
 * plus tard la cession expirait : le bien restait au vendeur alors que
 * l'acheteur l'avait déjà payé et emporté. C'est précisément le litige que le
 * registre existe pour éviter.
 *
 * DEUX FACTEURS, ET LES DEUX SONT ÉPROUVÉS ICI. Le jeton du lien prouve la
 * réception du courriel ; le code prouve la lecture de la boîte. Un test qui
 * n'exigerait que l'un des deux laisserait passer un lien transféré.
 *
 * N'utilise pas RefreshDatabase : le transfert écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('transfers')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerInvitations();

    $this->sender = new CapteurInvitation;
    $this->app->instance(OtpSender::class, $this->sender);
    $this->transferts = app(TransferService::class);

    Mail::fake();
});
afterEach(fn () => nettoyerInvitations());

function nettoyerInvitations(): void
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

/** Retient les codes émis, et LA DESTINATION RÉELLE de livraison. */
class CapteurInvitation implements OtpSender
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

function cedantVerifie(): User
{
    $utilisateur = User::create(['phone' => '+2250701'.random_int(100000, 999999)]);

    $utilisateur->forceFill([
        'kyc_status' => KycStatus::Verified->value,
        'kyc_verified_at' => now()->subMonth(),
    ])->save();

    return $utilisateur;
}

function bienACeder(User $proprietaire): Asset
{
    $vin = '1M8GDM9AXKP04'.random_int(1000, 9999);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => $vin,
        'identifier_normalized' => $vin,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Documented,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subYear(),
    ]);
}

/** Ouvre une cession vers une adresse et rend le transfert. */
function cessionVers(string $adresse, ?User $vendeur = null): Transfer
{
    $cedant = $vendeur ?? cedantVerifie();

    return test()->transferts->initiate(
        bienACeder($cedant),
        $cedant,
        '+2250799'.random_int(100000, 999999),
        $adresse,
    );
}

it('LIVRE LE CODE À L\'ADRESSE, ET NON À UN NUMÉRO QUI NE REÇOIT RIEN', function (): void {
    // LE défaut d'origine. Le code partait vers un numéro, par une passerelle
    // SMS qui n'est pas branchée : l'acheteur n'était jamais prévenu.
    $transfert = cessionVers('acheteur@exemple.ci');

    expect(test()->sender->pour('acheteur@exemple.ci'))->not->toBe('')
        ->and(test()->sender->pour($transfert->to_phone))->toBe('');
});

it('ENVOIE UNE INVITATION QUI DIT LE BIEN, ET NE NOMME PAS LE VENDEUR', function (): void {
    // Un code seul, sans contexte, se lit comme une escroquerie — et il a
    // raison de se lire ainsi. Mais nommer le cédant ferait sortir une identité
    // vers une adresse que personne n'a vérifiée (règle métier absolue n° 4).
    $cedant = cedantVerifie();
    $cedant->forceFill(['full_name' => 'Awa Koné'])->save();

    cessionVers('acheteur@exemple.ci', $cedant);

    Mail::assertSent(TransferInvitationMail::class, function (TransferInvitationMail $lettre): bool {
        $rendu = $lettre->render();

        return str_contains($rendu, 'Yamaha Crux')
            && ! str_contains($rendu, 'Awa Koné');
    });
});

it('N\'ENVOIE PAS LE CODE DANS L\'INVITATION', function (): void {
    // Deux messages, deux chemins : le lien décrit, le code autorise. Les
    // réunir ferait d'un courriel transféré une cession volée.
    cessionVers('acheteur@exemple.ci');

    $code = test()->sender->pour('acheteur@exemple.ci');

    Mail::assertSent(TransferInvitationMail::class,
        fn (TransferInvitationMail $lettre): bool => ! str_contains($lettre->render(), $code));
});

it('OUVRE LA PAGE SUR LE JETON, ET SUR RIEN D\'AUTRE', function (): void {
    $transfert = cessionVers('acheteur@exemple.ci');

    test()->get('/cession/'.$transfert->invite_token)
        ->assertOk()
        ->assertSee('Yamaha Crux');

    // 404 pour tous les cas : distinguer « jeton inconnu » de « cession
    // expirée » confirmerait qu'un jeton a existé.
    test()->get('/cession/'.str_repeat('a', 64))->assertNotFound();
});

it('N\'INDEXE JAMAIS CETTE PAGE', function (): void {
    // Elle décrit un bien identifiable et une transaction en cours.
    $transfert = cessionVers('acheteur@exemple.ci');

    test()->get('/cession/'.$transfert->invite_token)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('REFUSE LE JETON SEUL, SANS LE CODE', function (): void {
    // Un courriel transféré, une boîte partagée, une capture d'écran : aucun
    // de ces incidents ne doit suffire à faire changer un bien de mains.
    $transfert = cessionVers('acheteur@exemple.ci');

    test()->post('/cession/'.$transfert->invite_token, ['code' => '000000'])
        ->assertOk()
        ->assertSee('Ce code est incorrect.');

    expect($transfert->fresh()?->status)->toBe(TransferStatus::Initiated);
});

it('ACCEPTE AVEC LE CODE, ET OUVRE UN COMPTE À L\'ADRESSE', function (): void {
    // L'acheteur n'a ni compte ni application : c'est le cas ordinaire au
    // marché, et c'est celui que ce chemin existe pour servir.
    $transfert = cessionVers('acheteur@exemple.ci');
    $code = test()->sender->pour('acheteur@exemple.ci');

    test()->post('/cession/'.$transfert->invite_token, ['code' => $code])->assertOk();

    $acheteur = User::query()->where('email', 'acheteur@exemple.ci')->first();

    expect($acheteur)->not->toBeNull()
        // SANS NUMÉRO : en inventer un rendrait le compte irrécupérable.
        ->and($acheteur?->phone)->toBeNull()
        ->and($transfert->fresh()?->status)->toBe(TransferStatus::BuyerConfirmed)
        ->and($transfert->fresh()?->to_user_id)->toBe($acheteur?->id);
});

it('N\'ANNONCE PAS UNE CESSION ACHEVÉE TANT QUE LE VENDEUR N\'A PAS CONFIRMÉ', function (): void {
    // La cession exige les DEUX confirmations. Dire « le bien est à vous »
    // ferait repartir l'acheteur convaincu d'un enregistrement qui n'a pas eu
    // lieu — le litige même que le registre existe pour éviter.
    $transfert = cessionVers('acheteur@exemple.ci');
    $code = test()->sender->pour('acheteur@exemple.ci');

    test()->post('/cession/'.$transfert->invite_token, ['code' => $code])
        ->assertOk()
        ->assertSee('En attente de la confirmation du vendeur')
        ->assertDontSee('Le bien est à vous');
});

it('ACHÈVE LA CESSION QUAND LES DEUX ONT CONFIRMÉ', function (): void {
    $cedant = cedantVerifie();
    $transfert = cessionVers('acheteur@exemple.ci', $cedant);
    $bienInitial = $transfert->asset_id;

    test()->post('/cession/'.$transfert->invite_token, [
        'code' => test()->sender->pour('acheteur@exemple.ci'),
    ])->assertOk();

    app(OtpService::class)->request($cedant->phone, OtpPurpose::Transfer);
    test()->transferts->confirmBySeller(
        $transfert->fresh() ?? $transfert,
        $cedant,
        test()->sender->pour($cedant->phone),
    );

    $acheve = $transfert->fresh();
    $acheteur = User::query()->where('email', 'acheteur@exemple.ci')->first();

    expect($acheve?->status)->toBe(TransferStatus::Completed)
        // Règle métier absolue n° 3 : l'ancien est archivé, un seul actif reste.
        ->and(Asset::query()->whereKey($bienInitial)->value('active_flag'))->toBeNull()
        ->and(Asset::query()->whereKey($acheve?->created_asset_id)->value('owner_user_id'))
        ->toBe($acheteur?->id)
        // Le nouveau bien repart en « déclaré » : les justificatifs du vendeur
        // prouvaient SA propriété, pas celle de l'acheteur.
        ->and(Asset::query()->whereKey($acheve?->created_asset_id)->value('trust_level'))
        ->toBe(TrustLevel::Declared);
});

it('NE ROUVRE PAS UNE CESSION DÉJÀ ACCEPTÉE', function (): void {
    // Rouvrir la page ferait croire à l'acheteur que son accord n'a pas pris,
    // et l'inviterait à ressaisir un code déjà consommé.
    $transfert = cessionVers('acheteur@exemple.ci');

    test()->post('/cession/'.$transfert->invite_token, [
        'code' => test()->sender->pour('acheteur@exemple.ci'),
    ])->assertOk();

    test()->get('/cession/'.$transfert->invite_token)->assertNotFound();
});

it('N\'ENVOIE AUCUNE INVITATION QUAND AUCUNE ADRESSE N\'EST DONNÉE', function (): void {
    // Le numéro reste le chemin d'origine : rien ne doit changer pour lui.
    $cedant = cedantVerifie();

    test()->transferts->initiate(bienACeder($cedant), $cedant, '+2250799123456');

    Mail::assertNothingSent();
});

it('NE MET PAS LE JETON DANS UNE SÉRIALISATION', function (): void {
    // Il ouvre la page qui décrit le bien cédé : le laisser sortir dans une
    // réponse rendue au vendeur le mettrait à portée de qui n'a rien reçu.
    $transfert = cessionVers('acheteur@exemple.ci');

    $json = $transfert->toJson();

    expect($json)->not->toContain((string) $transfert->invite_token)
        ->and($json)->not->toContain('acheteur@exemple.ci');
});
