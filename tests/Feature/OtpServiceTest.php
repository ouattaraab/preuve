<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Enums\OtpRefus;
use App\Exceptions\OtpRefuseException;
use App\Models\OtpCode;
use App\Services\Otp\OtpSender;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ST-0101 / ST-0102 : code à 6 chiffres, expiration 5 minutes, 3 tentatives,
 * anti-brute-force progressif.
 */
beforeEach(function (): void {
    $this->sender = new FakeOtpSender;
    $this->app->instance(OtpSender::class, $this->sender);
    $this->otp = app(OtpService::class);
});

function dernierCode(): string
{
    return test()->sender->dernierCode();
}

it('émet un code à six chiffres, valide cinq minutes', function (): void {
    $defi = $this->otp->request('+2250700000001', OtpPurpose::Login);

    expect(dernierCode())->toMatch('/^\d{6}$/')
        ->and($defi->expires_at->diffInMinutes(now()))->toEqualWithDelta(-5, 1)
        ->and($defi->max_attempts)->toBe(3);
});

it('lit ses paramètres dans la configuration du projet, pas ailleurs', function (): void {
    // config/preuve.php est la source de vérité des paramètres métier
    // (verrouillée par ConfigurationMutualiseeTest). Un second fichier de
    // configuration parallèle laisserait un réglage modifié sans effet.
    config()->set('preuve.otp.length', 4);
    config()->set('preuve.otp.max_attempts', 7);

    $defi = $this->otp->request('+2250700000001', OtpPurpose::Login);

    expect(dernierCode())->toMatch('/^\d{4}$/')
        ->and($defi->max_attempts)->toBe(7);
});

it('ne stocke jamais le code en clair', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);

    $code = dernierCode();
    $ligne = OtpCode::sole();

    expect($ligne->code_hash)->not->toContain($code)
        ->and($ligne->code_hash)->toHaveLength(64)
        // L'empreinte est un HMAC porté par APP_KEY, pas un simple SHA-256 :
        // un code à six chiffres n'a qu'un million de valeurs possibles, donc
        // un SHA-256 nu se renverse par force brute en quelques secondes sur
        // un dump de base volé.
        ->and($ligne->code_hash)->not->toBe(hash('sha256', $code));
});

it('lie l\'empreinte à la destination et au motif', function (): void {
    // Une empreinte transplantable d'une ligne à l'autre laisserait un accès
    // en écriture à la base rejouer un code valide vers un autre numéro.
    $this->otp->request('+2250700000001', OtpPurpose::Login);
    $premier = OtpCode::sole();
    $code = dernierCode();

    OtpCode::query()->delete();
    $this->otp->request('+2250700000002', OtpPurpose::Login);

    expect(OtpCode::sole()->code_hash)->not->toBe($premier->code_hash)
        ->and(fn () => $this->otp->verify('+2250700000002', $code, OtpPurpose::Login))
        ->toThrow(OtpRefuseException::class);
});

it('accepte le bon code et le consomme une seule fois', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);
    $code = dernierCode();

    $valide = $this->otp->verify('+2250700000001', $code, OtpPurpose::Login);

    expect($valide->consumed_at)->not->toBeNull()
        // Rejouer le même code doit échouer : sans cela, un code intercepté
        // resterait utilisable jusqu'à son expiration.
        ->and(fn () => $this->otp->verify('+2250700000001', $code, OtpPurpose::Login))
        ->toThrow(OtpRefuseException::class);
});

it('refuse un code émis pour un autre motif', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);
    $code = dernierCode();

    expect(fn () => $this->otp->verify('+2250700000001', $code, OtpPurpose::Transfer))
        ->toThrow(OtpRefuseException::class);
});

it('refuse un code expiré', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);
    $code = dernierCode();

    $this->travel(6)->minutes();

    expect(fn () => $this->otp->verify('+2250700000001', $code, OtpPurpose::Login))
        ->toThrow(
            fn (OtpRefuseException $e) => expect($e->raison)->toBe(OtpRefus::CodeExpire)
        );
});

it('verrouille la destination après trois tentatives infructueuses', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);

    foreach (range(1, 3) as $essai) {
        try {
            $this->otp->verify('+2250700000001', '000000', OtpPurpose::Login);
        } catch (OtpRefuseException) {
            // Attendu.
        }
    }

    expect(fn () => $this->otp->verify('+2250700000001', dernierCode(), OtpPurpose::Login))
        ->toThrow(
            fn (OtpRefuseException $e) => expect($e->raison)->toBe(OtpRefus::Verrouille)
        );
});

it('ne laisse pas une nouvelle demande lever le verrouillage en cours', function (): void {
    // Le cœur de l'anti-brute-force : si redemander un code repartait de zéro,
    // le plafond de tentatives ne coûterait qu'un aller-retour à l'attaquant.
    $this->otp->request('+2250700000001', OtpPurpose::Login);

    foreach (range(1, 3) as $essai) {
        try {
            $this->otp->verify('+2250700000001', '000000', OtpPurpose::Login);
        } catch (OtpRefuseException) {
            // Attendu.
        }
    }

    expect(fn () => $this->otp->request('+2250700000001', OtpPurpose::Login))
        ->toThrow(
            fn (OtpRefuseException $e) => expect($e->raison)->toBe(OtpRefus::Verrouille)
        );
});

it('allonge le verrouillage à chaque récidive', function (): void {
    $verrouillages = [];

    foreach (range(1, 3) as $tour) {
        // Une heure entre chaque tour : assez pour que le verrouillage
        // précédent soit levé et que le plafond horaire d'envois soit remis à
        // zéro, mais assez peu pour que les trois verrouillages restent dans
        // la fenêtre d'observation de 24 h qui fait croître la sanction.
        $this->travel(1)->hour();
        $this->otp->request('+2250700000001', OtpPurpose::Login);

        foreach (range(1, 3) as $essai) {
            try {
                $this->otp->verify('+2250700000001', '000000', OtpPurpose::Login);
            } catch (OtpRefuseException) {
                // Attendu.
            }
        }

        $verrouille = OtpCode::whereNotNull('locked_until')->orderByDesc('id')->firstOrFail();
        $verrouillages[] = (int) $verrouille->locked_until?->diffInMinutes(now(), true);
    }

    expect($verrouillages[1])->toBeGreaterThan($verrouillages[0])
        ->and($verrouillages[2])->toBeGreaterThan($verrouillages[1]);
});

it('impose un délai minimal entre deux envois vers la même destination', function (): void {
    // Sans ce délai, un tiers pourrait faire pilonner de SMS le téléphone d'un
    // utilisateur, aux frais de la plateforme.
    $this->otp->request('+2250700000001', OtpPurpose::Login);

    expect(fn () => $this->otp->request('+2250700000001', OtpPurpose::Login))
        ->toThrow(
            fn (OtpRefuseException $e) => expect($e->raison)->toBe(OtpRefus::TropDeDemandes)
        );
});

it('plafonne le nombre d\'envois par heure vers la même destination', function (): void {
    foreach (range(1, 5) as $envoi) {
        $this->travel(2)->minutes();
        $this->otp->request('+2250700000001', OtpPurpose::Login);
    }

    $this->travel(2)->minutes();

    expect(fn () => $this->otp->request('+2250700000001', OtpPurpose::Login))
        ->toThrow(
            fn (OtpRefuseException $e) => expect($e->raison)->toBe(OtpRefus::TropDeDemandes)
        );
});

it('invalide le code précédent dès qu\'un nouveau est émis', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);
    $ancien = dernierCode();

    $this->travel(2)->minutes();
    $this->otp->request('+2250700000001', OtpPurpose::Login);

    expect(fn () => $this->otp->verify('+2250700000001', $ancien, OtpPurpose::Login))
        ->toThrow(OtpRefuseException::class)
        ->and($this->otp->verify('+2250700000001', dernierCode(), OtpPurpose::Login)->consumed_at)
        ->not->toBeNull();
});

it('normalise le numéro avant toute recherche', function (): void {
    // « 07 00 00 00 01 » et « +225 07-00-00-00-01 » sont le même abonné : sans
    // normalisation, chaque écriture désignerait une destination différente et
    // le plafond de tentatives se contournerait par un simple espace.
    $this->otp->request('07 00 00 00 01', OtpPurpose::Login);

    expect(OtpCode::sole()->destination)->toBe('+2250700000001')
        ->and($this->otp->verify('+225 07-00-00-00-01', dernierCode(), OtpPurpose::Login)->consumed_at)
        ->not->toBeNull();
});

it('rejette une destination qui n\'est pas un numéro exploitable', function (): void {
    expect(fn () => $this->otp->request('pas-un-numero', OtpPurpose::Login))
        ->toThrow(
            fn (OtpRefuseException $e) => expect($e->raison)->toBe(OtpRefus::DestinationInvalide)
        );
});

it('ne révèle jamais le code dans le message d\'une exception', function (): void {
    $this->otp->request('+2250700000001', OtpPurpose::Login);
    $code = dernierCode();

    try {
        $this->otp->verify('+2250700000001', '000000', OtpPurpose::Login);
    } catch (OtpRefuseException $e) {
        expect($e->getMessage())->not->toContain($code);
    }
});

/** Capte les codes émis au lieu de les envoyer par SMS. */
class FakeOtpSender implements OtpSender
{
    /** @var list<array{destination: string, code: string}> */
    public array $envois = [];

    public function send(string $destination, string $code, OtpPurpose $purpose): void
    {
        $this->envois[] = ['destination' => $destination, 'code' => $code];
    }

    public function dernierCode(): string
    {
        return $this->envois[count($this->envois) - 1]['code'];
    }
}
