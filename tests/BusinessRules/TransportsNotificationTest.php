<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use App\Services\Delivery\FcmPushTransport;
use App\Services\NotificationService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * ST-1003 (push FCM) et ST-1004 (SMS critique).
 *
 * Une alerte critique doit atteindre un propriétaire qui n'ouvre pas
 * l'application — c'est tout l'objet de ces transports. Mais un SMS banalisé
 * apprendrait à ignorer les alertes, exactement celles qui comptent.
 *
 * N'utilise pas RefreshDatabase : certaines notifications viennent d'actions
 * auditées.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('device_tokens')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerTransports();

    // Pas de Http::fake global ici : les stubs sont évalués dans leur ordre
    // d'insertion, si bien qu'un « tout » posé en beforeEach l'emporterait sur
    // le stub d'erreur qu'un test veut poser ensuite. Chaque test déclare donc
    // le sien.
    Http::preventStrayRequests();
    $this->settings = app(SettingsRepository::class)->fresh();
});
afterEach(fn () => nettoyerTransports());

function nettoyerTransports(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'sms_deliveries', 'device_tokens', 'notifications',
        'app_settings', 'personal_access_tokens', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function destinataire(): User
{
    return User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
}

function configurerPush(User $utilisateur): void
{
    test()->settings->set(FcmPushTransport::SERVER_KEY_SETTING, 'cle-fcm');

    DB::table('device_tokens')->insert([
        'user_id' => $utilisateur->id,
        'token' => 'jeton-'.$utilisateur->id,
        'platform' => 'android',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function configurerSmsNotification(): void
{
    test()->settings->set('sms.provider', 'http');
    test()->settings->set('sms.config', [
        'endpoint_url' => 'https://api.exemple.ci/sms',
        'payload_template' => '{"to":"{{destination}}","text":"{{message}}"}',
    ]);
}

it('fonctionne sans aucun transport configuré', function (): void {
    // Le centre de notifications suffit : rien ne doit échouer faute de
    // passerelle.
    $utilisateur = destinataire();

    app(NotificationService::class)->notify(
        $utilisateur,
        NotificationType::DuplicateAttempt,
        'Alerte',
        'Corps',
    );

    expect(Notification::count())->toBe(1)
        ->and(DB::table('sms_deliveries')->count())->toBe(0);

    Http::assertNothingSent();
});

it('envoie un push pour toute notification quand il est configuré', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $utilisateur = destinataire();
    configurerPush($utilisateur);

    app(NotificationService::class)->notify(
        $utilisateur,
        NotificationType::AssetLookup,
        'Votre bien a été consulté',
        'Consulté 3 fois.',
    );

    Http::assertSent(fn ($requete): bool => str_contains($requete->url(), 'fcm'));
});

it('n\'envoie un SMS que pour les alertes critiques', function (): void {
    // Un SMS banalisé apprendrait aux propriétaires à ignorer les alertes.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $utilisateur = destinataire();
    configurerSmsNotification();
    $service = app(NotificationService::class);

    $service->notify($utilisateur, NotificationType::AssetLookup, 'Consultation', 'Corps');

    expect(DB::table('sms_deliveries')->count())->toBe(0);

    $service->notify($utilisateur, NotificationType::DuplicateAttempt, 'Tentative', 'Corps');

    expect(DB::table('sms_deliveries')->where('status', 'sent')->count())->toBe(1);
});

it('ne met aucun détail dans le SMS', function (): void {
    // Il s'affiche sur un écran verrouillé, parfois sous les yeux du voleur si
    // le téléphone a été pris avec le bien.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $utilisateur = destinataire();
    configurerSmsNotification();

    app(NotificationService::class)->notify(
        $utilisateur,
        NotificationType::DuplicateAttempt,
        'Tentative d\'enregistrement de votre bien',
        'Quelqu\'un a tenté d\'enregistrer un bien portant le même identifiant : 1M8GDM9AXKP042788.',
    );

    Http::assertSent(function ($requete): bool {
        $texte = (string) ($requete['text'] ?? '');

        return ! str_contains($texte, '1M8GDM9AXKP042788')
            && str_contains($texte, 'Ouvrez l\'application');
    });
});

it('trace le coût sans le destinataire', function (): void {
    // Savoir combien de SMS partent et pourquoi suffit à piloter la dépense ;
    // un journal nominatif ajouterait une donnée personnelle à protéger.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $utilisateur = destinataire();
    configurerSmsNotification();

    app(NotificationService::class)->notify(
        $utilisateur,
        NotificationType::LookupSpike,
        'Pic de consultations',
        'Corps',
    );

    $ligne = DB::table('sms_deliveries')->sole();

    expect($ligne->notification_type)->toBe('lookup_spike')
        ->and($ligne->status)->toBe('sent')
        ->and(json_encode($ligne))->not->toContain($utilisateur->phone);
});

it('n\'échoue jamais quand la passerelle est en panne', function (): void {
    // Une déclaration de vol ne peut pas être refusée parce qu'un agrégateur
    // répond mal.
    Http::fake(['*' => Http::response(['erreur' => 'quota'], 500)]);
    $utilisateur = destinataire();
    configurerSmsNotification();

    $notification = app(NotificationService::class)->notify(
        $utilisateur,
        NotificationType::DuplicateAttempt,
        'Tentative',
        'Corps',
    );

    expect($notification)->not->toBeNull()
        ->and(DB::table('sms_deliveries')->where('status', 'failed')->count())->toBe(1);
});

it('respecte le silence demandé, même avec les transports actifs', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $utilisateur = destinataire();
    configurerPush($utilisateur);
    configurerSmsNotification();

    $utilisateur->forceFill(['notification_preferences' => ['asset_lookup' => false]])->save();

    app(NotificationService::class)->notify(
        $utilisateur->fresh() ?? $utilisateur,
        NotificationType::AssetLookup,
        'Consultation',
        'Corps',
    );

    expect(Notification::count())->toBe(0);
    Http::assertNothingSent();
});

it('enregistre un appareil et le rattache au compte courant', function (): void {
    // Un téléphone revendu peut se présenter avec un jeton déjà connu :
    // l'ancien propriétaire ne doit pas continuer à recevoir les alertes du
    // nouveau.
    $premier = destinataire();
    Sanctum::actingAs($premier);
    $this->postJson('/api/v1/devices', ['token' => 'jeton-partage'])->assertStatus(201);

    $second = destinataire();
    Sanctum::actingAs($second);
    $this->postJson('/api/v1/devices', ['token' => 'jeton-partage'])->assertStatus(201);

    expect(DB::table('device_tokens')->count())->toBe(1)
        ->and(DB::table('device_tokens')->value('user_id'))->toBe($second->id);
});

it('retire un appareil', function (): void {
    $utilisateur = destinataire();
    Sanctum::actingAs($utilisateur);
    $this->postJson('/api/v1/devices', ['token' => 'jeton-a-retirer'])->assertStatus(201);

    $this->deleteJson('/api/v1/devices', ['token' => 'jeton-a-retirer'])->assertOk();

    expect(DB::table('device_tokens')->count())->toBe(0);
});

it('expose le coût SMS à l\'administrateur sans la clé push', function (): void {
    $admin = destinataire();
    $admin->forceFill(['role' => UserRole::Admin])->save();
    Sanctum::actingAs($admin);

    $this->putJson('/api/v1/admin/push-provider', ['server_key' => 'cle-fcm-secrete'])->assertOk();

    $reponse = $this->getJson('/api/v1/admin/push-provider')->assertOk();

    expect($reponse->content())->not->toContain('cle-fcm-secrete')
        ->and($reponse->json('configured'))->toBeTrue()
        ->and($reponse->json('sms_cost_30d'))->toBe([]);
});
