<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Règle métier absolue n° 4, versant symétrique : le propriétaire ne voit
 * JAMAIS l'identité du consultant — même quand celui-ci a payé un rapport.
 * ST-0107 : les préférences permettent de se taire, sauf sur ce qui met le
 * bien en péril.
 */
function proprietaireAvecBien(): array
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    $bien = Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(3),
    ]);

    return [$proprietaire, $bien];
}

it('dépose une notification lisible au centre de notifications', function (): void {
    [$proprietaire, $bien] = proprietaireAvecBien();

    app(NotificationService::class)->notify(
        $proprietaire,
        NotificationType::DuplicateAttempt,
        'Tentative d\'enregistrement',
        'Quelqu\'un a tenté d\'enregistrer un bien portant votre identifiant.',
        $bien,
    );

    $notification = Notification::sole();

    expect($notification->user_id)->toBe($proprietaire->id)
        ->and($notification->asset_id)->toBe($bien->id)
        ->and($notification->type)->toBe(NotificationType::DuplicateAttempt)
        ->and($notification->read_at)->toBeNull();
});

it('n\'écrit jamais l\'identité d\'un tiers dans une notification de consultation', function (): void {
    // Le propriétaire apprend QUE son bien est consulté, jamais PAR QUI :
    // l'inverse transformerait la plateforme en outil de traque.
    [$proprietaire, $bien] = proprietaireAvecBien();

    // Identifiant volontairement improbable : avec un petit entier,
    // l'assertion d'absence passerait sur le compteur lui-même.
    DB::table('users')->insert([
        'id' => 987654, 'phone' => '+2250777777777', 'full_name' => 'Kouassi B.',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $consultant = User::findOrFail(987654);

    app(NotificationService::class)->notifyLookupActivity($bien, 3, now()->subHour(), now());

    $notification = Notification::sole();

    expect($notification->body)->not->toContain('Kouassi B.')
        ->and(json_encode($notification->payload))->not->toContain((string) $consultant->id)
        ->and($notification->payload)->not->toHaveKey('ip_hash')
        ->and($notification->payload['count'] ?? null)->toBe(3);
});

it('compte les consultations sans jamais les détailler une à une', function (): void {
    // Une notification par consultation apprendrait au propriétaire le rythme
    // exact des visites, donc indirectement qui regarde et quand.
    [, $bien] = proprietaireAvecBien();

    app(NotificationService::class)->notifyLookupActivity($bien, 7, now()->subHour(), now());

    expect(Notification::count())->toBe(1)
        ->and(Notification::sole()->body)->toContain('7');
});

it('respecte le silence demandé sur un type facultatif', function (): void {
    [$proprietaire, $bien] = proprietaireAvecBien();
    $proprietaire->forceFill(['notification_preferences' => ['asset_lookup' => false]])->save();

    app(NotificationService::class)->notifyLookupActivity($bien->fresh(), 3, now()->subHour(), now());

    expect(Notification::count())->toBe(0);
});

it('passe outre le silence pour un événement critique', function (): void {
    // Couper l'alerte d'une tentative d'enregistrement frauduleux laisserait un
    // propriétaire perdre son bien sans jamais l'apprendre : ce type n'est pas
    // désactivable.
    [$proprietaire, $bien] = proprietaireAvecBien();
    $proprietaire->forceFill(['notification_preferences' => [
        'asset_lookup' => false,
        'duplicate_attempt' => false,
    ]])->save();

    app(NotificationService::class)->notify(
        $proprietaire->fresh(),
        NotificationType::DuplicateAttempt,
        'Tentative d\'enregistrement',
        'Quelqu\'un a tenté d\'enregistrer un bien portant votre identifiant.',
        $bien,
    );

    expect(Notification::count())->toBe(1);
});

it('reçoit tout par défaut sur un compte neuf', function (): void {
    // Un propriétaire ne doit pas avoir à activer les alertes sur ses propres
    // biens pour être protégé.
    [, $bien] = proprietaireAvecBien();

    app(NotificationService::class)->notifyLookupActivity($bien, 2, now()->subHour(), now());

    expect(Notification::count())->toBe(1);
});

it('réserve le SMS aux événements critiques', function (): void {
    [$proprietaire, $bien] = proprietaireAvecBien();
    $service = app(NotificationService::class);

    $service->notifyLookupActivity($bien, 2, now()->subHour(), now());
    $service->notify($proprietaire, NotificationType::DuplicateAttempt, 'Alerte', 'Corps', $bien);

    $consultation = Notification::where('type', NotificationType::AssetLookup->value)->sole();
    $tentative = Notification::where('type', NotificationType::DuplicateAttempt->value)->sole();

    expect($consultation->channel)->toBe('inapp')
        ->and($tentative->channel)->toBe('sms');
});

it('n\'accepte pas de couper un type critique dans les préférences', function (): void {
    [$proprietaire] = proprietaireAvecBien();

    $retenues = app(NotificationService::class)->updatePreferences($proprietaire, [
        'asset_lookup' => false,
        'duplicate_attempt' => false,
        'claim_opened' => false,
    ]);

    expect($retenues['asset_lookup'])->toBeFalse()
        // Les demandes portant sur des types critiques sont ignorées, pas
        // rejetées : l'utilisateur garde une interface qui répond.
        ->and($retenues)->not->toHaveKey('duplicate_attempt')
        ->and($retenues)->not->toHaveKey('claim_opened');
});

it('ignore une préférence portant sur un type inexistant', function (): void {
    [$proprietaire] = proprietaireAvecBien();

    $retenues = app(NotificationService::class)->updatePreferences($proprietaire, [
        'asset_lookup' => false,
        'type_invente' => false,
    ]);

    expect($retenues)->toBe(['asset_lookup' => false]);
});

it('ne notifie pas un bien archivé', function (): void {
    // Le bien a changé de mains : l'ancien détenteur n'a plus à savoir qui le
    // consulte.
    [, $bien] = proprietaireAvecBien();
    Asset::whereKey($bien->id)->update(['active_flag' => null]);

    app(NotificationService::class)->notifyLookupActivity($bien->fresh(), 3, now()->subHour(), now());

    expect(Notification::count())->toBe(0);
});
