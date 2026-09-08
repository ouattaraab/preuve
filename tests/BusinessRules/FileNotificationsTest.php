<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Delivery\FcmPushTransport;
use App\Services\NotificationService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Acheminement des notifications par la file (ST-1003, ST-1004).
 *
 * L'envoi partait auparavant dans la requête qui le déclenchait : quelques
 * centaines de millisecondes ajoutées aux actions critiques, et un utilisateur
 * qui attend une passerelle.
 *
 * Ce que la file apporte et qui n'était pas possible avant : le RÉESSAI. En
 * synchrone, un échec ne laissait que deux choix — faire échouer l'action
 * métier, ou perdre l'alerte. Aucun des deux n'est acceptable pour une
 * déclaration de vol.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('notifications')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerFileNotifs();
    Http::preventStrayRequests();
});
afterEach(fn () => nettoyerFileNotifs());

function nettoyerFileNotifs(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['app_settings', 'sms_deliveries', 'device_tokens', 'notifications', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function destinataireFile(): User
{
    return User::create(['phone' => '+22507'.random_int(10000000, 99999999)]);
}

it('écrit la notification tout de suite et met le seul acheminement en file', function (): void {
    // La ligne du centre in-app est la trace durable : un propriétaire doit la
    // trouver en ouvrant l'application, même si aucune passerelle n'a répondu.
    // Mettre l'écriture en file ferait dépendre le registre d'un travailleur.
    Queue::fake();

    app(NotificationService::class)->notify(
        destinataireFile(),
        NotificationType::DuplicateAttempt,
        'Tentative sur votre bien',
        'Corps',
    );

    expect(Notification::count())->toBe(1);

    Queue::assertPushed(DeliverNotification::class);
});

it('dépêche sur la file dédiée, et après le COMMIT', function (): void {
    // La file `notifications` est séparée pour qu'un afflux d'alertes ne
    // retarde rien d'autre. `afterCommit` parce que le travailleur charge la
    // notification par son identifiant : dépêché avant le COMMIT, il pourrait
    // la chercher avant qu'elle n'existe.
    Queue::fake();

    app(NotificationService::class)->notify(
        destinataireFile(),
        NotificationType::System,
        'Titre',
        'Corps',
    );

    Queue::assertPushed(DeliverNotification::class, function (DeliverNotification $job): bool {
        return $job->queue === 'notifications' && $job->afterCommit === true;
    });
});

it('rejoue un échec critique au lieu de le perdre', function (): void {
    // LE gain de la file. En synchrone, l'échec était absorbé et l'alerte
    // perdue — il n'y avait rien d'autre à faire sans refuser l'action métier.
    // Dans le travailleur, personne n'attend : lever déclenche le réessai.
    $destinataire = destinataireFile();
    $destinataire->forceFill(['email' => 'proprietaire@exemple.ci'])->save();

    $notification = Notification::create([
        'user_id' => $destinataire->id,
        'type' => NotificationType::DuplicateAttempt,
        'title' => 'Tentative sur votre bien',
        'body' => 'Corps',
        'channel' => 'sms',
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    Mail::shouldReceive('to')
        ->andThrow(new RuntimeException('passerelle injoignable'));

    expect(fn () => app(NotificationService::class)->deliverQueued($notification))
        ->toThrow(RuntimeException::class);

    // L'échec est consigné avant d'être relancé : la tentative doit se voir,
    // même quand elle sera rejouée.
    $trace = DB::table('sms_deliveries')->first();

    expect($trace?->status)->toBe('failed')
        ->and($trace?->channel)->toBe('mail');
});

it('ne rejoue pas un échec de push, qui ne le mérite pas', function (): void {
    // Le push accompagne tout ce qui arrive au centre : il ne coûte rien et
    // n'est pas critique. Le rejouer encombrerait la file pour un gain nul.
    $destinataire = destinataireFile();

    app(SettingsRepository::class)->set(FcmPushTransport::SERVER_KEY_SETTING, 'cle-fcm');
    app(SettingsRepository::class)->fresh();

    DB::table('device_tokens')->insert([
        'user_id' => $destinataire->id,
        'token' => 'jeton-test',
        'platform' => 'android',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(['*' => fn () => throw new RuntimeException('FCM injoignable')]);

    $notification = Notification::create([
        'user_id' => $destinataire->id,
        // Type NON critique : ni SMS ni courriel ne suivront.
        'type' => NotificationType::StatusChange,
        'title' => 'Titre',
        'body' => 'Corps',
        'channel' => 'inapp',
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    app(NotificationService::class)->deliverQueued($notification);

    expect(true)->toBeTrue();
});

it('ne fait jamais échouer l\'action métier, même file en panne', function (): void {
    // L'invariant tient quel que soit le pilote : avec un vrai pilote,
    // dispatch() n'écrit qu'une ligne ; sur `sync`, le travail a lieu dans la
    // requête. Une file elle-même en panne ne doit pas non plus refuser une
    // déclaration de vol.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('file injoignable'));

    $notification = app(NotificationService::class)->notify(
        destinataireFile(),
        NotificationType::DuplicateAttempt,
        'Tentative sur votre bien',
        'Corps',
    );

    // La notification existe : c'est ce qui compte.
    expect($notification)->not->toBeNull()
        ->and(Notification::count())->toBe(1);
});
