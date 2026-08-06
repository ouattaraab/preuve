<?php

declare(strict_types=1);

use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\ReportPurchase;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReportAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EP-08 (ST-0801 à ST-0803, ST-0806) et règles métier absolues n° 4 et n° 7.
 *
 * Deux exigences tirent en sens contraire : personne n'accède au rapport sans
 * être identifié, et pourtant personne n'apprend l'identité de personne. Payer
 * n'achète pas le droit de savoir QUI, seulement celui de savoir QUOI.
 *
 * N'utilise pas RefreshDatabase : l'achat écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('payments')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerPaiements();
    $this->rapports = app(ReportAccessService::class);
    $this->paiements = app(PaymentService::class);
});
afterEach(fn () => nettoyerPaiements());

function nettoyerPaiements(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'report_purchases', 'payments',
        'asset_status_history', 'assets', 'otp_codes', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function proprietaireDuBien(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    $utilisateur->forceFill([
        'full_name' => 'Awa Koné',
        'kyc_status' => KycStatus::Verified->value,
    ])->save();

    return $utilisateur;
}

function bienRapportable(User $proprietaire): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Documented,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(8),
    ]);
}

function paiementAbouti(Asset $bien, ?User $acheteur = null): Payment
{
    return Payment::create([
        'user_id' => $acheteur?->id,
        'buyer_name' => $acheteur === null ? 'Yao N.' : null,
        'buyer_email' => $acheteur === null ? 'yao@example.ci' : null,
        'buyer_phone' => $acheteur === null ? '+2250777777777' : null,
        'purpose' => 'detailed_report',
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'REF-'.bin2hex(random_bytes(4)),
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now(),
    ]);
}

it('ouvre un accès de trente jours après paiement', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $achat = $this->rapports->grant(paiementAbouti($bien), $bien);

    expect(strlen($achat->access_token))->toBe(40)
        ->and($achat->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('refuse d\'ouvrir un accès sans paiement abouti', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $paiement = paiementAbouti($bien);
    $paiement->forceFill(['status' => PaymentStatus::Pending])->save();

    expect(fn () => $this->rapports->grant($paiement, $bien))->toThrow(DomainException::class);
});

it('n\'ouvre qu\'un seul accès pour un même paiement', function (): void {
    // Un webhook rejoué ne doit pas créditer deux rapports.
    $bien = bienRapportable(proprietaireDuBien());
    $paiement = paiementAbouti($bien);

    $premier = $this->rapports->grant($paiement, $bien);
    $second = $this->rapports->grant($paiement, $bien);

    expect($second->id)->toBe($premier->id)
        ->and(ReportPurchase::count())->toBe(1);
});

it('livre l\'historique et le nombre de détenteurs, jamais les identités', function (): void {
    // Règle métier absolue n° 4 : payer n'achète pas le droit de savoir QUI.
    $proprietaire = proprietaireDuBien();
    $bien = bienRapportable($proprietaire);
    $achat = $this->rapports->grant(paiementAbouti($bien), $bien);

    $rapport = $this->rapports->read($achat->access_token);
    $json = json_encode($rapport);

    expect($rapport['ownership']['holders_count'])->toBe(1)
        ->and($rapport['asset']['public_ref'])->toBe($bien->public_ref)
        ->and($json)->not->toContain('Awa Koné')
        ->and($json)->not->toContain($proprietaire->phone)
        // L'identifiant complet du bien n'y figure pas davantage : le rapport
        // ne doit pas servir à constituer un annuaire.
        ->and($json)->not->toContain('1M8GDM9AXKP042788');
});

it('compte les détenteurs successifs sans les nommer', function (): void {
    $premier = proprietaireDuBien();
    $bien = bienRapportable($premier);

    // Enregistrement précédent archivé, comme après un transfert.
    Asset::create([
        'public_ref' => 'PRV-ANCIEN01',
        'owner_user_id' => proprietaireDuBien()->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => null,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subYears(2),
    ]);

    $achat = $this->rapports->grant(paiementAbouti($bien), $bien);
    $rapport = $this->rapports->read($achat->access_token);

    expect($rapport['ownership']['holders_count'])->toBe(2)
        ->and($rapport['ownership']['changed_at'])->toHaveCount(1);
});

it('refuse un jeton inconnu ou expiré', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $achat = $this->rapports->grant(paiementAbouti($bien), $bien);

    expect(fn () => $this->rapports->read('jeton-invente'))->toThrow(DomainException::class);

    $this->travel(31)->days();

    expect(fn () => $this->rapports->read($achat->access_token))->toThrow(DomainException::class);
});

it('compte les accès au rapport', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $achat = $this->rapports->grant(paiementAbouti($bien), $bien);

    $this->rapports->read($achat->access_token);
    $this->rapports->read($achat->access_token);

    expect($achat->fresh()?->access_count)->toBe(2)
        ->and($achat->fresh()?->first_access_at)->not->toBeNull();
});

it('prévient le détenteur sans lui dire qui a acheté', function (): void {
    // ST-0803 : le signal a de la valeur — une transaction se prépare
    // peut-être sans lui — mais il n'apprend jamais qui.
    $proprietaire = proprietaireDuBien();
    $bien = bienRapportable($proprietaire);

    $this->rapports->grant(paiementAbouti($bien), $bien);

    $alerte = Notification::where('user_id', $proprietaire->id)->sole();

    expect($alerte->type)->toBe(NotificationType::AssetReportPurchased)
        ->and($alerte->body)->not->toContain('Yao N.')
        ->and($alerte->body)->not->toContain('2250777777777')
        ->and($alerte->body)->toContain('jamais qui achète');
});

it('n\'écrit pas l\'identité de l\'acheteur dans la chaîne d\'audit', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $this->rapports->grant(paiementAbouti($bien), $bien);

    $entree = AuditLog::where('action', 'report.purchased')->sole();

    expect(json_encode($entree->payload))->not->toContain('Yao N.')
        ->and(json_encode($entree->payload))->not->toContain('yao@example.ci');
});

it('n\'accepte aucun paiement sans payeur identifiable', function (): void {
    // Règle métier absolue n° 7, portée par une contrainte en base : elle
    // résiste à un accès direct, pas seulement au code applicatif.
    $bien = bienRapportable(proprietaireDuBien());

    expect(fn () => Payment::create([
        'user_id' => null,
        'purpose' => 'detailed_report',
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::PawapayWave,
        'status' => PaymentStatus::Pending,
    ]))->toThrow(QueryException::class);
});

it('ne propose jamais CinetPay', function (): void {
    // Interdit par la stack : l'énumération et l'ENUM en base rendent son
    // usage impossible, pas seulement déconseillé.
    $operateurs = array_column(PaymentProvider::cases(), 'value');

    expect($operateurs)->not->toContain('cinetpay')
        ->and($operateurs)->toContain('paystack', 'pawapay_wave', 'pawapay_om', 'pawapay_momo');
});

it('réconcilie un webhook rejoué sans dupliquer', function (): void {
    // ST-0806 : un opérateur mobile renvoie le même événement plusieurs fois.
    $bien = bienRapportable(proprietaireDuBien());
    $paiement = Payment::create([
        'user_id' => proprietaireDuBien()->id,
        'purpose' => 'detailed_report',
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::PawapayWave,
        'provider_ref' => 'REF-UNIQUE',
        'status' => PaymentStatus::Pending,
    ]);

    $this->paiements->reconcile(PaymentProvider::PawapayWave, 'REF-UNIQUE', PaymentStatus::Succeeded);
    $this->paiements->fulfill($paiement->fresh() ?? $paiement);

    $this->paiements->reconcile(PaymentProvider::PawapayWave, 'REF-UNIQUE', PaymentStatus::Succeeded);
    $this->paiements->fulfill($paiement->fresh() ?? $paiement);

    expect(Payment::count())->toBe(1)
        ->and(ReportPurchase::count())->toBe(1)
        ->and($paiement->fresh()?->status)->toBe(PaymentStatus::Succeeded);
});

it('ne réécrit pas un état déjà définitif', function (): void {
    // Un webhook tardif ne repasse pas en « payé » une transaction remboursée.
    $bien = bienRapportable(proprietaireDuBien());
    $paiement = Payment::create([
        'user_id' => proprietaireDuBien()->id,
        'purpose' => 'detailed_report',
        'related_id' => $bien->id,
        'amount_fcfa' => 1000,
        'provider' => PaymentProvider::Paystack,
        'provider_ref' => 'REF-REMBOURSE',
        'status' => PaymentStatus::Refunded,
    ]);

    $this->paiements->reconcile(PaymentProvider::Paystack, 'REF-REMBOURSE', PaymentStatus::Succeeded);

    expect($paiement->fresh()?->status)->toBe(PaymentStatus::Refunded);
});

it('ignore un événement qui ne correspond à aucun paiement', function (): void {
    $resultat = $this->paiements->reconcile(PaymentProvider::Paystack, 'REF-INCONNUE', PaymentStatus::Succeeded);

    expect($resultat)->toBeNull();
});

it('refuse un événement sans référence', function (): void {
    expect(fn () => $this->paiements->reconcile(PaymentProvider::Paystack, '', PaymentStatus::Succeeded))
        ->toThrow(DomainException::class);
});

/*
|--------------------------------------------------------------------------
| Le rapport payé, lisible sur n'importe quel appareil (ST-0802)
|--------------------------------------------------------------------------
|
| La règle n° 7 autorise l'achat SANS COMPTE. Jusqu'ici un tel acheteur payait
| et recevait un jeton dans une réponse HTTP : ni application, ni page, rien à
| ouvrir. De l'argent entrait, rien d'utilisable n'en sortait.
*/

it('OUVRE LE RAPPORT PAYÉ SANS AUCUN COMPTE', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    test()->get('/rapport/'.$acces->access_token)
        ->assertOk()
        ->assertSee($bien->public_ref)
        ->assertSee('détenteur(s) depuis');
});

it('NE NOMME PERSONNE, jamais', function (): void {
    // Règle métier absolue n° 4. Le rapport apporte le NOMBRE de détenteurs et
    // les DATES de changement — c'est ce qu'un acheteur a besoin de juger — et
    // pas les personnes.
    $proprietaire = proprietaireDuBien();
    $bien = bienRapportable($proprietaire);
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    $page = test()->get('/rapport/'.$acces->access_token)->assertOk();

    $page->assertDontSee('Awa Koné')
        ->assertDontSee($proprietaire->phone)
        // Ni le numéro réel du bien : le lien se transmet à un garagiste, et ne
        // doit rien livrer de plus que ce qui a été acheté.
        ->assertDontSee('1M8GDM9AXKP042788');
});

it('N\'EST NI INDEXÉE NI MISE EN CACHE', function (): void {
    // L'adresse porte la capacité de lire un rapport payé. Un cache partagé —
    // celui d'un cybercafé, d'un proxy d'entreprise — le rendrait au suivant.
    $bien = bienRapportable(proprietaireDuBien());
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    $page = test()->get('/rapport/'.$acces->access_token)->assertOk();

    expect($page->headers->get('Cache-Control'))->toContain('no-store');
    // Le jeton est DANS l'URL : sans cet en-tête, le moindre lien sortant
    // l'expédierait au site visité.
    expect($page->headers->get('Referrer-Policy'))->toBe('no-referrer');
    $page->assertSee('noindex', false);
});

it('NE CHARGE AUCUNE RESSOURCE TIERCE', function (): void {
    // C'est ce qui empêche le jeton de fuir par un en-tête `Referer` vers un
    // régisseur, et ce qui rend la page utilisable en 3G (CT-05).
    $bien = bienRapportable(proprietaireDuBien());
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    $html = test()->get('/rapport/'.$acces->access_token)->getContent();

    expect($html)->not->toMatch('#(src|href)="https?://(?!preuve)#i');
});

it('DIT POURQUOI quand le lien ne mène nulle part', function (): void {
    // Un lien expiré se rachète, un lien erroné se revérifie : la conduite à
    // tenir diffère, et le message doit la donner.
    $page = test()->get('/rapport/'.str_repeat('z', 40))
        ->assertNotFound()
        ->assertSee('RAPPORT INDISPONIBLE')
        ->assertSee('invalide');

    // LES MÊMES EN-TÊTES QUE LA PAGE TROUVÉE : l'adresse d'un jeton expiré
    // porte encore un jeton réel, et la page d'échec doit se protéger autant.
    expect($page->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($page->headers->get('Cache-Control'))->toContain('no-store');
});

it('LE MÊME CODE POUR UN JETON INCONNU ET UN JETON EXPIRÉ', function (): void {
    // Faire varier le code de statut donnerait à un automate de quoi éprouver
    // des jetons au hasard, un par un.
    $bien = bienRapportable(proprietaireDuBien());
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);
    $acces->forceFill(['expires_at' => now()->subDay()])->save();

    $expire = test()->get('/rapport/'.$acces->access_token)->assertNotFound();
    $inconnu = test()->get('/rapport/'.str_repeat('z', 40))->assertNotFound();

    expect($expire->getStatusCode())->toBe($inconnu->getStatusCode());
    // Le MESSAGE, lui, distingue : l'acheteur légitime doit savoir s'il doit
    // racheter ou s'il s'est trompé de lien.
    $expire->assertSee('expiré');
});

it('COMPTE L\'ACCÈS, comme la lecture par l\'API', function (): void {
    $bien = bienRapportable(proprietaireDuBien());
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    test()->get('/rapport/'.$acces->access_token)->assertOk();

    expect($acces->fresh()?->access_count)->toBe(1);
});

it('PARLE FRANÇAIS, dates comprises', function (): void {
    // `translatedFormat` suit la locale de l'application. Elle valait « en » par
    // défaut : la page de consultation — la promesse n° 1 du produit, lue debout
    // sur un parking à Abidjan — affichait « 6 December 2025 ».
    $bien = bienRapportable(proprietaireDuBien());
    $bien->forceFill(['registered_at' => \Illuminate\Support\Carbon::parse('2025-12-06')])->save();
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    test()->get('/rapport/'.$acces->access_token)
        ->assertOk()
        ->assertSee('6 décembre 2025')
        ->assertDontSee('December');
});

it('DIT L\'ORIGINE EN LANGAGE COURANT, jamais son code (CT-04)', function (): void {
    // Le rapport affichait « owner » à un acheteur. Et c'est l'ORIGINE, jamais
    // l'auteur : « une décision d'arbitrage » informe, « décidé par Awa Koné »
    // dénonce (règle métier absolue n° 4).
    $bien = bienRapportable(proprietaireDuBien());

    \App\Models\AssetStatusHistory::create([
        'asset_id' => $bien->id,
        'from_status' => 'V-ACT',
        'to_status' => 'V-VOL',
        'trigger_type' => \App\Enums\TriggerType::Owner,
    ]);

    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    test()->get('/rapport/'.$acces->access_token)
        ->assertOk()
        ->assertSee('Une action du détenteur')
        ->assertDontSee('>owner<', false);
});

it('REND L\'ADRESSE PARTAGEABLE, pas seulement le jeton', function (): void {
    // Un jeton nu se lit avec un client d'API ; personne d'autre n'en fait
    // rien. C'est l'adresse qui s'ouvre sur n'importe quel appareil.
    $bien = bienRapportable(proprietaireDuBien());
    $acces = app(ReportAccessService::class)->grant(paiementAbouti($bien), $bien);

    test()->getJson('/api/v1/reports/access/'.$acces->access_token)
        ->assertOk()
        ->assertJsonPath('report_url', url('/rapport/'.$acces->access_token));
});
