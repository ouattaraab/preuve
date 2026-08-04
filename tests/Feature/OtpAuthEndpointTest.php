<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\Otp\OtpSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-0101 (création de compte par OTP) et ST-0102 (reconnexion sur un nouvel
 * appareil). N'utilise pas RefreshDatabase : la vérification réussie écrit
 * dans la chaîne d'audit, qui refuse toute transaction englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('personal_access_tokens')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerAuth();

    $this->sender = new CaptureOtpSender;
    $this->app->instance(OtpSender::class, $this->sender);
});
afterEach(fn () => nettoyerAuth());

function nettoyerAuth(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'company_members', 'companies', 'personal_access_tokens', 'otp_codes', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function demanderCode(string $telephone = '0700000001', string $motif = 'login'): void
{
    test()->postJson('/api/v1/auth/otp/request', [
        'phone' => $telephone,
        'purpose' => $motif,
    ])->assertOk();
}

it('émet un code sans exiger d\'authentification', function (): void {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '0700000001', 'purpose' => 'login'])
        ->assertOk()
        ->assertJsonStructure(['message', 'expires_in']);

    expect($this->sender->envois)->toHaveCount(1);
});

it('ne révèle jamais si un compte existe déjà pour ce numéro', function (): void {
    // Une réponse différente entre numéro connu et inconnu ferait du parcours
    // de connexion un service d'énumération d'abonnés.
    User::create(['phone' => '+2250700000001']);

    $connu = $this->postJson('/api/v1/auth/otp/request', ['phone' => '0700000001', 'purpose' => 'login']);

    $this->travel(2)->minutes();

    $inconnu = $this->postJson('/api/v1/auth/otp/request', ['phone' => '0700000009', 'purpose' => 'login']);

    expect($connu->json())->toBe($inconnu->json())
        ->and($connu->status())->toBe($inconnu->status());
});

it('ne renvoie jamais le code dans la réponse', function (): void {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '0700000001', 'purpose' => 'login'])
        ->assertOk();

    expect($this->postJson('/api/v1/auth/otp/request', ['phone' => '0700000002', 'purpose' => 'login'])->content())
        ->not->toContain($this->sender->dernierCode());
});

it('rejette un numéro inexploitable', function (): void {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => 'pas-un-numero', 'purpose' => 'login'])
        ->assertStatus(422);
});

it('crée le compte et ouvre une session au premier code vérifié', function (): void {
    demanderCode();

    $reponse = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ]);

    $reponse->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'phone']]);

    $utilisateur = User::sole();

    expect($utilisateur->phone)->toBe('+2250700000001')
        ->and($utilisateur->phone_verified_at)->not->toBeNull()
        ->and($reponse->json('user.phone'))->toBe('+2250700000001');
});

it('retrouve le compte existant au lieu d\'en créer un second', function (): void {
    $existant = User::create(['phone' => '+2250700000001', 'full_name' => 'Awa K.']);

    demanderCode();

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->assertOk()->assertJsonPath('user.id', $existant->id);

    expect(User::count())->toBe(1);
});

it('délivre un jeton qui ouvre réellement les routes authentifiées', function (): void {
    demanderCode();

    $token = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->json('token');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('phone', '+2250700000001');
});

it('refuse les routes authentifiées sans jeton valide', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer jeton-invente')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('révoque les autres appareils à la demande (ST-0102)', function (): void {
    demanderCode();
    $premier = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->json('token');

    $this->travel(2)->minutes();
    demanderCode();
    $second = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
        'revoke_other_devices' => true,
    ])->json('token');

    $this->withHeader('Authorization', 'Bearer '.$premier)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer '.$second)->getJson('/api/v1/auth/me')->assertOk();
});

it('conserve les autres appareils par défaut', function (): void {
    demanderCode();
    $premier = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->json('token');

    $this->travel(2)->minutes();
    demanderCode();
    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$premier)->getJson('/api/v1/auth/me')->assertOk();
});

it('ferme la session courante à la déconnexion', function (): void {
    demanderCode();
    $token = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->json('token');

    $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/auth/logout')->assertOk();

    // Le guard mémorise l'utilisateur résolu pour la durée du test : sans
    // cette remise à zéro, la requête suivante serait servie depuis ce cache
    // et non depuis le jeton, ce qui masquerait une révocation qui n'aurait
    // pas eu lieu.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('refuse un code erroné sans ouvrir de session', function (): void {
    demanderCode();

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => '000000',
    ])->assertStatus(401);

    expect(User::count())->toBe(0);
});

it('répond 429 avec un délai quand la destination est verrouillée', function (): void {
    demanderCode();

    foreach (range(1, 3) as $essai) {
        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0700000001',
            'purpose' => 'login',
            'code' => '000000',
        ]);
    }

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => '000000',
    ])->assertStatus(429)->assertHeader('Retry-After');
});

it('journalise l\'ouverture de session dans la chaîne d\'audit sans écrire le numéro en clair', function (): void {
    // Loi 2013-450, minimisation : audit_log est inaltérable, un numéro de
    // téléphone écrit en clair y resterait après tout exercice du droit à
    // l'effacement (ST-0105).
    demanderCode();

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->assertOk();

    $entrees = AuditLog::orderBy('id')->get();
    $utilisateur = User::sole();

    expect($entrees)->not->toBeEmpty()
        ->and($entrees->pluck('action')->all())->toContain('auth.session_opened')
        ->and($entrees->last()->actor_id)->toBe($utilisateur->id)
        ->and(json_encode($entrees->pluck('payload')->all()))->not->toContain('2250700000001');
});

it('journalise la création de compte distinctement d\'une simple reconnexion', function (): void {
    demanderCode();
    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->assertOk();

    $premieres = AuditLog::pluck('action')->all();

    $this->travel(2)->minutes();
    demanderCode();
    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->assertOk();

    $toutes = AuditLog::pluck('action')->all();

    expect($premieres)->toContain('auth.account_created')
        ->and(array_slice($toutes, count($premieres)))->not->toContain('auth.account_created');
});

it('n\'accepte pas un code émis pour un motif différent', function (): void {
    demanderCode(motif: 'transfer');

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '0700000001',
        'purpose' => 'login',
        'code' => $this->sender->dernierCode(),
    ])->assertStatus(401);
});

it('valide les motifs recevables sur le parcours de connexion', function (): void {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '0700000001', 'purpose' => 'motif-inconnu'])
        ->assertStatus(422);
});

/** Capte les codes émis au lieu de les envoyer par SMS. */
class CaptureOtpSender implements OtpSender
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

it('dit à quelles sociétés le compte appartient', function (): void {
    // SANS CELA, LA FLOTTE EST INATTEIGNABLE : tous ses points d'entrée sont en
    // `/fleet/{company}/…`, et rien ne disait à l'application qu'un compte est
    // un loueur, ni de quelle société. Le tableau de bord existait et n'était
    // ouvrable par personne.
    $loueur = User::create(['phone' => '+2250701020304']);

    $societe = Company::create([
        'owner_user_id' => $loueur->id,
        'legal_name' => 'Loueur Abidjan',
        'rccm_number' => 'CI-ABJ-2026-B-'.random_int(10000, 99999),
        'business_type' => 'car_rental',
        'validation_status' => 'validated',
    ]);

    CompanyMember::create([
        'company_id' => $societe->id,
        'user_id' => $loueur->id,
        // L'invitation se fait par NUMÉRO : le compte peut ne pas exister
        // encore au moment où on l'invite.
        'invited_phone' => $loueur->phone,
        'role' => CompanyRole::Admin,
        'is_active' => true,
    ]);

    Sanctum::actingAs($loueur);

    $reponse = $this->getJson('/api/v1/auth/me')->assertOk();

    expect($reponse->json('companies.0.id'))->toBe($societe->id)
        ->and($reponse->json('companies.0.name'))->toBe('Loueur Abidjan')
        // LE RÔLE VIENT DU SERVEUR : il décide de ce que l'écran propose, et le
        // deviner ferait afficher des boutons que le serveur refuse.
        ->and($reponse->json('companies.0.role'))->toBe('admin');
});

it('ne rattache aucune société à un compte ordinaire', function (): void {
    Sanctum::actingAs(User::create(['phone' => '+2250701020305']));

    expect($this->getJson('/api/v1/auth/me')->assertOk()->json('companies'))->toBe([]);
});
