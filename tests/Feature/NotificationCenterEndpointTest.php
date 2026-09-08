<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** ST-1001 (centre in-app) et ST-0107 (préférences). */
function connecte(): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);
    Sanctum::actingAs($utilisateur);

    return $utilisateur;
}

function notificationPour(User $destinataire, ?NotificationType $type = null, bool $lue = false): Notification
{
    return Notification::create([
        'user_id' => $destinataire->id,
        'type' => $type ?? NotificationType::AssetLookup,
        'title' => 'Votre bien a été consulté',
        'body' => 'Votre bien a été consulté 3 fois.',
        'payload' => ['count' => 3],
        'channel' => 'inapp',
        'read_at' => $lue ? now() : null,
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);
}

it('exige une authentification', function (): void {
    $this->getJson('/api/v1/notifications')->assertStatus(401);
    $this->getJson('/api/v1/notification-preferences')->assertStatus(401);
});

it('sert le fil et le compte de non-lus', function (): void {
    $utilisateur = connecte();
    notificationPour($utilisateur);
    notificationPour($utilisateur);
    notificationPour($utilisateur, lue: true);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 2)
        ->assertJsonCount(3, 'notifications')
        ->assertJsonStructure(['notifications' => [['id', 'type', 'title', 'body', 'read', 'created_at']]]);
});

it('ne montre jamais le fil d\'un autre utilisateur', function (): void {
    // Lire l'activité d'autrui reviendrait à savoir quels biens sont
    // consultés, donc à contourner l'anonymat par la porte de service.
    $autre = User::create(['phone' => '+2250799999999']);
    notificationPour($autre);

    connecte();

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'notifications')
        ->assertJsonPath('unread_count', 0);
});

it('marque une notification comme lue', function (): void {
    $utilisateur = connecte();
    $notification = notificationPour($utilisateur);

    $this->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('notification.read', true);

    expect($notification->fresh()?->read_at)->not->toBeNull();
});

it('répond 404 sur la notification d\'un autre, jamais 403', function (): void {
    // Un « interdit » confirmerait l'existence de la notification, donc
    // l'activité sur le bien d'autrui.
    $autre = User::create(['phone' => '+2250799999999']);
    $notification = notificationPour($autre);

    connecte();

    $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(404);

    expect($notification->fresh()?->read_at)->toBeNull();
});

it('marque tout le fil comme lu', function (): void {
    $utilisateur = connecte();
    notificationPour($utilisateur);
    notificationPour($utilisateur);

    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('marked', 2)
        ->assertJsonPath('unread_count', 0);

    expect(Notification::unread()->count())->toBe(0);
});

it('ne marque pas le fil des autres en marquant le sien', function (): void {
    $autre = User::create(['phone' => '+2250799999999']);
    $sienne = notificationPour($autre);

    $utilisateur = connecte();
    notificationPour($utilisateur);

    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('marked', 1);

    expect($sienne->fresh()?->read_at)->toBeNull();
});

it('expose les seules préférences réellement modifiables', function (): void {
    // Proposer une case à cocher qui ne fait rien serait pire que de ne pas la
    // proposer : les types critiques ne figurent pas au catalogue.
    connecte();

    $reponse = $this->getJson('/api/v1/notification-preferences')->assertOk();
    $types = array_column($reponse->json('available'), 'type');

    expect($types)->toContain('asset_lookup')
        ->and($types)->not->toContain('duplicate_attempt')
        ->and($types)->not->toContain('claim_opened');
});

it('enregistre le silence demandé sur un type facultatif', function (): void {
    connecte();

    $this->putJson('/api/v1/notification-preferences', [
        'preferences' => ['asset_lookup' => false],
    ])->assertOk()->assertJsonPath('preferences.asset_lookup', false);

    $this->getJson('/api/v1/notification-preferences')
        ->assertOk()
        ->assertJsonPath('preferences.asset_lookup', false);
});

it('ignore une tentative de couper un type critique', function (): void {
    connecte();

    $this->putJson('/api/v1/notification-preferences', [
        'preferences' => ['duplicate_attempt' => false],
    ])->assertOk()->assertJsonMissingPath('preferences.duplicate_attempt');
});

it('exige des préférences bien formées', function (): void {
    connecte();

    $this->putJson('/api/v1/notification-preferences', [])->assertStatus(422);
});
