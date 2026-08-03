<?php

declare(strict_types=1);

use App\Enums\OtpChannel;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Décision D7 : acheminement des codes par courriel, faute de fournisseur SMS.
 *
 * LE TÉLÉPHONE RESTE L'IDENTITÉ ; le courriel n'est qu'un canal de livraison.
 * Cette séparation n'est pas cosmétique : verrouillage, plafond de rythme et
 * empreinte du code portent tous sur le numéro. Les faire porter sur l'adresse
 * permettrait de contourner un verrouillage en changeant simplement de boîte.
 *
 * N'utilise pas RefreshDatabase : la création de compte écrit dans la chaîne
 * d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerOtpMail();
    Mail::fake();

    $reglages = app(SettingsRepository::class);
    $reglages->set(ConfigurableOtpSender::PROVIDER_KEY, 'mail');
    $reglages->fresh();
});
afterEach(fn () => nettoyerOtpMail());

function nettoyerOtpMail(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'app_settings', 'otp_codes', 'personal_access_tokens', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function demanderCodeMail(string $telephone, ?string $email = null): TestResponse
{
    return test()->postJson('/api/v1/auth/otp/request', array_filter([
        'phone' => $telephone,
        'purpose' => 'login',
        'email' => $email,
    ]));
}

it('n\'envoie JAMAIS le code à une adresse soumise pour un compte existant', function (): void {
    // LE test de ce canal. Accepter une adresse soumise permettrait à quiconque
    // connaît un numéro de faire envoyer le code chez lui — c'est-à-dire de
    // prendre n'importe quel compte, sans rien savoir d'autre. C'est le point
    // où le courriel se distingue du SMS, où la maîtrise du numéro fait preuve.
    $victime = User::create(['phone' => '+2250701020304']);
    $victime->forceFill(['email' => 'victime@exemple.ci'])->save();

    demanderCodeMail('+2250701020304', 'attaquant@exemple.ci')->assertOk();

    Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->hasTo('victime@exemple.ci'));
    Mail::assertNotSent(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->hasTo('attaquant@exemple.ci'));
});

it('n\'envoie rien pour un compte existant sans adresse, sans le dire', function (): void {
    // Le refus ne doit pas être observable : il apprendrait au demandeur que le
    // compte existe.
    User::create(['phone' => '+2250701020304']);

    $reponse = demanderCodeMail('+2250701020304', 'attaquant@exemple.ci')->assertOk();

    Mail::assertNothingSent();

    // Réponse strictement identique à celle d'un numéro inconnu.
    $inconnu = demanderCodeMail('+2250709998877');

    expect($reponse->json())->toBe($inconnu->json());
});

it('envoie à l\'adresse soumise quand le compte n\'existe pas encore', function (): void {
    // Création : l'adresse soumise est la seule dont on dispose, et la vérifier
    // prouvera qu'elle appartient au demandeur.
    demanderCodeMail('+2250701020304', 'nouveau@exemple.ci')->assertOk();

    Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->hasTo('nouveau@exemple.ci'));
});

it('n\'envoie rien si aucune adresse n\'est fournie pour un compte inconnu', function (): void {
    demanderCodeMail('+2250701020304')->assertOk();

    Mail::assertNothingSent();
});

it('n\'atteste que ce qui a été prouvé', function (): void {
    // Un code reçu par courriel ne prouve pas la maîtrise du NUMÉRO. Marquer
    // `phone_verified_at` inscrirait une vérification qui n'a pas eu lieu, sur
    // la colonne même qui atteste qu'elle a eu lieu.
    demanderCodeMail('+2250701020304', 'nouveau@exemple.ci')->assertOk();

    $code = codeEmisParMail('+2250701020304');

    test()->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2250701020304',
        'purpose' => 'login',
        'code' => $code,
        'email' => 'nouveau@exemple.ci',
    ])->assertOk();

    $compte = User::where('phone', '+2250701020304')->first();

    expect($compte?->email)->toBe('nouveau@exemple.ci')
        ->and($compte?->email_verified_at)->not->toBeNull()
        // Le numéro n'a rien prouvé : il reste non vérifié.
        ->and($compte?->phone_verified_at)->toBeNull();
});

it('fait porter le verrouillage sur le numéro, pas sur l\'adresse', function (): void {
    // Sans cette séparation, un attaquant épuiserait ses tentatives puis
    // changerait d'adresse pour repartir d'un compteur neuf.
    $limite = (int) config('preuve.otp.max_attempts');

    demanderCodeMail('+2250701020304', 'premier@exemple.ci')->assertOk();

    foreach (range(1, $limite) as $essai) {
        test()->postJson('/api/v1/auth/otp/verify', [
            'phone' => '+2250701020304',
            'purpose' => 'login',
            // 401 : « saisie incorrecte », que le client doit distinguer du
            // 429 « réessayez plus tard » pour ne pas relancer en boucle.
            'code' => '000000',
        ])->assertStatus(401);
    }

    // Nouvelle adresse, même numéro : le verrouillage tient.
    demanderCodeMail('+2250701020304', 'seconde@exemple.ci')->assertStatus(429);
});

it('consigne le canal réellement emprunté', function (): void {
    // `otp_codes.channel` sert à savoir ce qui a été prouvé : il doit dire la
    // vérité, pas le défaut.
    demanderCodeMail('+2250701020304', 'nouveau@exemple.ci')->assertOk();

    expect(OtpCode::first()?->channel)->toBe(OtpChannel::Email);
});

it('ne met pas le code dans l\'objet du message', function (): void {
    // Un objet s'affiche sur un écran verrouillé, parfois sous les yeux d'un
    // tiers — ou du voleur, si l'appareil a été pris avec le bien.
    demanderCodeMail('+2250701020304', 'nouveau@exemple.ci')->assertOk();

    Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail): bool {
        return ! str_contains($mail->envelope()->subject, $mail->code)
            && str_contains($mail->render(), $mail->code);
    });
});

/** Relit le code émis, que seul le destinataire connaît en exploitation. */
function codeEmisParMail(string $telephone): string
{
    $capture = '';

    Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$capture): bool {
        $capture = $mail->code;

        return true;
    });

    return $capture;
}

it('atteste la boîte d\'un compte existant à sa première connexion', function (): void {
    // Un compte créé à la main par l'exploitant n'a rien prouvé tant que
    // personne ne s'y est connecté. C'est cette première connexion qui
    // l'atteste, et elle doit s'inscrire — symétriquement au SMS.
    $compte = User::create(['phone' => '+2250701020304']);
    $compte->forceFill(['email' => 'titulaire@exemple.ci'])->save();

    expect($compte->email_verified_at)->toBeNull();

    demanderCodeMail('+2250701020304')->assertOk();

    test()->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2250701020304',
        'purpose' => 'login',
        'code' => codeEmisParMail('+2250701020304'),
    ])->assertOk();

    expect($compte->fresh()?->email_verified_at)->not->toBeNull()
        // Le numéro, lui, n'a toujours rien prouvé.
        ->and($compte->fresh()?->phone_verified_at)->toBeNull();
});
