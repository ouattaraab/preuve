<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ST-0402 : le statut « Enregistrement récent » ouvre une fenêtre de
 * contestation de 30 jours, au terme de laquelle le bien devient « Actif » —
 * mais seulement si aucune réclamation n'a été jugée recevable entre-temps.
 *
 * Cette condition n'a pas besoin d'être exprimée : une réclamation recevable
 * fait passer le bien en « Litige en cours » (matrice, systemPatterns §1), donc
 * hors du périmètre du job. C'est ce que vérifient les tests ci-dessous, et
 * c'est ce qui devra rester vrai quand la table des réclamations existera.
 *
 * N'utilise pas RefreshDatabase : la transition écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_status_history')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerPromotion();
});
afterEach(fn () => nettoyerPromotion());

function nettoyerPromotion(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'notifications', 'asset_status_history', 'assets', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienProvisoire(
    ?LifeStatus $statut = null,
    ?string $echeance = null,
    ?int $actif = 1,
): Asset {
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'identifier_normalized' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'active_flag' => $actif,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut ?? LifeStatus::Provisional,
        'provisional_until' => $echeance ?? now()->subDay()->format('Y-m-d H:i:s'),
        'registered_at' => now()->subDays(31),
    ]);
}

it('bascule en Actif au terme de la fenêtre de contestation', function (): void {
    $bien = bienProvisoire();

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('laisse la fenêtre courir jusqu\'à son terme', function (): void {
    // Promouvoir avant l'échéance viderait la contestation de son objet.
    $bien = bienProvisoire(echeance: now()->addDays(5)->format('Y-m-d H:i:s'));

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Provisional);
});

it('ne touche pas un bien gelé par une réclamation recevable', function (): void {
    // La condition « sans réclamation recevable » de ST-0402 n'a pas à être
    // exprimée : la recevabilité a déjà fait passer le bien en litige, donc
    // hors du périmètre du job.
    $bien = bienProvisoire(statut: LifeStatus::Disputed);

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Disputed)
        ->and(AssetStatusHistory::count())->toBe(0);
});

it('ne touche pas un bien déclaré volé pendant la fenêtre', function (): void {
    $bien = bienProvisoire(statut: LifeStatus::Stolen);

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen);
});

it('ignore un enregistrement archivé', function (): void {
    // Le bien a changé de mains : sa ligne archivée n'a plus de statut de vie
    // à faire évoluer.
    $bien = bienProvisoire(actif: null);

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Provisional);
});

it('ignore un bien sans échéance renseignée', function (): void {
    // Sans terme, il n'y a pas de fenêtre à clore : mieux vaut laisser le bien
    // en l'état qu'inventer une date.
    $bien = bienProvisoire();
    Asset::whereKey($bien->id)->update(['provisional_until' => null]);

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Provisional);
});

it('inscrit la bascule à l\'historique comme décision du système', function (): void {
    $bien = bienProvisoire();

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    $ligne = AssetStatusHistory::where('asset_id', $bien->id)->sole();

    expect($ligne->from_status)->toBe(LifeStatus::Provisional)
        ->and($ligne->to_status)->toBe(LifeStatus::Active)
        // Aucun acteur humain : c'est le terme du délai qui décide.
        ->and($ligne->trigger_type)->toBe(TriggerType::System)
        ->and($ligne->actor_user_id)->toBeNull();
});

it('journalise la bascule dans la chaîne d\'audit', function (): void {
    $bien = bienProvisoire();

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    $entree = AuditLog::where('action', 'asset.status_transition')->sole();

    expect($entree->entity_id)->toBe($bien->id)
        ->and($entree->payload['to_status'] ?? null)->toBe('V-ACT')
        ->and($entree->actor_id)->toBeNull();
});

it('prévient le propriétaire que son bien est désormais actif', function (): void {
    $bien = bienProvisoire();

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    $notification = Notification::where('user_id', $bien->owner_user_id)->sole();

    expect($notification->type)->toBe(NotificationType::StatusChange)
        ->and($notification->asset_id)->toBe($bien->id)
        ->and($notification->body)->toContain('Actif');
});

it('ne repasse jamais deux fois sur le même bien', function (): void {
    $bien = bienProvisoire();

    $this->artisan('preuve:promote-provisional')->assertSuccessful();
    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect(AssetStatusHistory::where('asset_id', $bien->id)->count())->toBe(1);
});

it('traite plusieurs biens échus au même passage', function (): void {
    bienProvisoire();
    bienProvisoire();
    bienProvisoire(echeance: now()->addDay()->format('Y-m-d H:i:s'));

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect(Asset::where('life_status', LifeStatus::Active->value)->count())->toBe(2)
        ->and(Asset::where('life_status', LifeStatus::Provisional->value)->count())->toBe(1);
});

it('borne le nombre de bascules par passage', function (): void {
    // Chaque transition prend le verrou nommé de la chaîne d'audit : un
    // passage sans borne, sur un parc important, ferait échouer les actions
    // des autres utilisateurs pendant tout son déroulement.
    bienProvisoire();
    bienProvisoire();
    bienProvisoire();

    $this->artisan('preuve:promote-provisional', ['--limit' => 2])->assertSuccessful();

    expect(Asset::where('life_status', LifeStatus::Active->value)->count())->toBe(2);
});

it('poursuit le passage malgré l\'échec d\'un bien', function (): void {
    // Un bien qui échoue ne doit pas retenir les autres : ils ont tous atteint
    // le terme de leur fenêtre et n'ont pas à attendre le passage suivant.
    $bloque = bienProvisoire();
    $sain = bienProvisoire();

    // Statut rendu incohérent directement en base : la transition demandée ne
    // figure pas dans la matrice, le service la refusera.
    DB::table('assets')->where('id', $bloque->id)->update(['life_status' => 'V-FDV']);

    $this->artisan('preuve:promote-provisional')->assertSuccessful();

    expect($sain->fresh()?->life_status)->toBe(LifeStatus::Active)
        ->and($bloque->fresh()?->life_status)->toBe(LifeStatus::EndOfLife);
});
