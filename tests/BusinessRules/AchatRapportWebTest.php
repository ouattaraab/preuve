<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\OtpCode;
use App\Models\Payment;
use App\Models\ReportPurchase;
use App\Models\User;
use App\Services\Otp\OtpSender;
use App\Services\PaystackGateway;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * ST-0801/ST-0802 : acheter le rapport détaillé depuis le web, sans compte.
 *
 * CE QUE CE PARCOURS RÉPARE. Tout existait côté serveur — achat invité, code
 * avant paiement, jeton d'accès, page de lecture — et l'application mobile le
 * proposait. Le web, lui, s'arrêtait sur le verdict avec « Vérifier un autre
 * bien » : la principale ligne de revenu n'était pas vendable sur le canal le
 * plus accessible, un navigateur sans installation.
 *
 * L'INVARIANT LE PLUS IMPORTANT ICI EST LA RÈGLE N° 7 : aucun accès n'est
 * ouvert avant qu'un code n'ait été vérifié. Vérifier après le paiement ne
 * servirait à rien — l'accès serait déjà donné.
 *
 * N'utilise pas RefreshDatabase : l'achat écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('report_purchases')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerAchatWeb();

    $this->sender = new CapteurAchatWeb;
    $this->app->instance(OtpSender::class, $this->sender);

    // Une clé Paystack posée comme l'espace admin la pose, et l'opérateur
    // simulé : aucun test ne sort sur le réseau.
    app(SettingsRepository::class)->setSecret(PaystackGateway::SECRET_SETTING, 'sk_test_'.str_repeat('a', 32));
    app(SettingsRepository::class)->fresh();

    Http::fake([
        'api.paystack.co/*' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/essai',
                'reference' => 'ref-simulee',
            ],
        ]),
    ]);
});
afterEach(fn () => nettoyerAchatWeb());

function nettoyerAchatWeb(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    // `lookups` EN FAIT PARTIE, et l'oubli s'est vu : ouvrir `/b/{ref}` pour
    // constater l'offre de rapport JOURNALISE une consultation. Ce fichier
    // n'utilise pas RefreshDatabase — ses écritures survivent donc au test —
    // et deux lignes oubliées ici faisaient échouer quatre tests d'un AUTRE
    // fichier, qui comptait les consultations d'une base qu'il croyait vierge.
    foreach ([
        'audit_log', 'payments', 'report_purchases', 'otp_codes', 'lookups',
        'asset_status_history', 'assets', 'app_settings', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    app(SettingsRepository::class)->fresh();
}

/** Retient les codes émis, et la destination RÉELLE de livraison. */
class CapteurAchatWeb implements OtpSender
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

function bienAchetableWeb(): Asset
{
    $proprietaire = User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);
    $numero = 'DD'.random_int(100, 999).'EF';

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'plate',
        'identifier_raw' => $numero,
        'identifier_normalized' => $numero,
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha XTZ 125'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(6),
    ]);
}

function tarifRapport(int $montant): void
{
    app(SettingsRepository::class)->set('pricing.report_fcfa', $montant);
    app(SettingsRepository::class)->fresh();
}

/** Renseigne le trio et rend le code réellement émis. */
function codeAcheteurWeb(Asset $bien, string $telephone = '+2250799112233'): string
{
    test()->post('/rapport/commander/'.$bien->public_ref.'/code', [
        'buyer_name' => 'Koffi Acheteur',
        'buyer_email' => 'acheteur@exemple.ci',
        'buyer_phone' => $telephone,
    ])->assertOk();

    // LIVRÉ À L'ADRESSE tant qu'aucune passerelle SMS n'est branchée : le
    // capteur le prouve, plutôt que de le supposer.
    return test()->sender->pour('acheteur@exemple.ci');
}

it('PROPOSE LE RAPPORT SUR LE VERDICT, AVEC SON PRIX', function (): void {
    // LE défaut d'origine : la page se terminait par « Vérifier un autre bien »
    // au moment de plus forte intention du produit.
    tarifRapport(1000);
    $bien = bienAchetableWeb();

    test()->get('/b/'.$bien->public_ref)
        ->assertOk()
        ->assertSee('1 000 FCFA')
        ->assertSee('/rapport/commander/'.$bien->public_ref);
});

it('N\'AFFICHE PAS DE MONTANT QUAND LE RAPPORT EST GRATUIT', function (): void {
    // Un prix codé dans le gabarit continuerait de s'afficher sur un rapport
    // devenu gratuit — et ferait renoncer quelqu'un qui n'avait rien à payer.
    tarifRapport(0);
    $bien = bienAchetableWeb();

    test()->get('/b/'.$bien->public_ref)
        ->assertOk()
        ->assertSee('GRATUIT')
        ->assertDontSee('1 000 FCFA');
});

it('DIT CE QU\'ON ACHÈTE, ET CE QU\'ON N\'ACHÈTE PAS', function (): void {
    // L'anonymat est symétrique et se dit AVANT l'achat : payer en croyant
    // obtenir un nom, c'est se sentir floué après.
    tarifRapport(1000);
    $bien = bienAchetableWeb();

    test()->get('/rapport/commander/'.$bien->public_ref)
        ->assertOk()
        ->assertSee('changé de mains')
        ->assertSee('Jamais de noms')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('LIVRE LE CODE À L\'ADRESSE, PAS À UN NUMÉRO QUI NE REÇOIT RIEN', function (): void {
    // Même piège que la cession : sans passerelle SMS, un code adressé à un
    // numéro ne part nulle part, et l'acheteur attend indéfiniment.
    tarifRapport(1000);
    $bien = bienAchetableWeb();

    $code = codeAcheteurWeb($bien);

    expect($code)->not->toBe('')
        ->and(test()->sender->pour('+2250799112233'))->toBe('');
});

it('N\'OUVRE AUCUN ACCÈS AVANT LE CODE (règle n° 7)', function (): void {
    tarifRapport(1000);
    $bien = bienAchetableWeb();

    codeAcheteurWeb($bien);

    test()->post('/rapport/commander/'.$bien->public_ref, ['code' => '000000'])
        ->assertOk()
        ->assertSee('Ce code est incorrect');

    expect(ReportPurchase::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('OUVRE LA CAISSE UNE FOIS LE CODE VÉRIFIÉ', function (): void {
    tarifRapport(1000);
    $bien = bienAchetableWeb();
    $code = codeAcheteurWeb($bien);

    test()->post('/rapport/commander/'.$bien->public_ref, ['code' => $code])
        ->assertRedirect('https://checkout.paystack.com/essai');

    $paiement = Payment::query()->first();

    expect($paiement?->purpose)->toBe(PaymentPurpose::DetailedReport)
        ->and($paiement?->amount_fcfa)->toBe(1000)
        ->and($paiement?->status)->toBe(PaymentStatus::Pending)
        // LA RÉFÉRENCE EST ÉCRITE AVANT LA REDIRECTION : c'est elle que le
        // webhook rapprochera. Sans elle, l'argent arrive et rien ne s'ouvre.
        //
        // C'EST LA NÔTRE, pas celle que l'opérateur renverrait : `PaystackGateway`
        // la tire à partir de l'identifiant du paiement et l'IMPOSE à Paystack,
        // précisément pour pouvoir rapprocher le rappel. La laisser tirer par
        // l'opérateur nous priverait de ce rapprochement.
        ->and($paiement?->provider_ref)->toStartWith('preuve-'.$paiement?->id.'-')
        // L'ACCÈS N'EST PAS ENCORE DONNÉ : seul le webhook signé l'ouvrira.
        ->and(ReportPurchase::query()->count())->toBe(0);
});

it('DONNE LE RAPPORT SUR-LE-CHAMP QUAND IL EST GRATUIT', function (): void {
    // Ouvrir une transaction de zéro franc la ferait échouer chez l'opérateur,
    // et exiger un moyen de paiement pour ne rien encaisser serait absurde.
    tarifRapport(0);
    $bien = bienAchetableWeb();
    $code = codeAcheteurWeb($bien);

    $reponse = test()->post('/rapport/commander/'.$bien->public_ref, ['code' => $code]);

    $acces = ReportPurchase::query()->first();

    expect($acces)->not->toBeNull()
        ->and(Payment::query()->count())->toBe(0);

    $reponse->assertRedirect(route('public.report', ['token' => $acces?->access_token]));
});

it('EXIGE LE TRIO COMPLET', function (): void {
    // Un rapport anonyme serait l'outil de repérage idéal.
    tarifRapport(1000);
    $bien = bienAchetableWeb();

    test()->post('/rapport/commander/'.$bien->public_ref.'/code', [
        'buyer_name' => 'Koffi',
        'buyer_email' => 'pas-une-adresse',
    ])->assertSessionHasErrors(['buyer_email', 'buyer_phone']);

    expect(OtpCode::query()->count())->toBe(0);
});

it('NE COMMANDE RIEN SUR UNE RÉFÉRENCE INCONNUE OU ARCHIVÉE', function (): void {
    $bien = bienAchetableWeb();
    $bien->forceFill(['active_flag' => null])->save();

    test()->get('/rapport/commander/'.$bien->public_ref)->assertNotFound();
    test()->get('/rapport/commander/PRV-ZZZZZZZZ')->assertNotFound();
});

it('RECOMMENCE PROPREMENT QUAND LA SESSION EST PERDUE', function (): void {
    // Onglet rouvert, cookie expiré : échouer sur un champ que l'acheteur ne
    // voit plus le laisserait sans issue.
    tarifRapport(1000);
    $bien = bienAchetableWeb();

    test()->post('/rapport/commander/'.$bien->public_ref, ['code' => '123456'])
        ->assertOk()
        ->assertSee('vos coordonnées n\'ont pas été retenues');
});
