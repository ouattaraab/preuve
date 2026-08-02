<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Exceptions\TransitionInterditeException;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\StatusTransitionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Règle métier absolue n° 6 : seules les transitions de la matrice sont
 * possibles, et chacune écrit dans asset_status_history.
 *
 * Ce fichier porte sa PROPRE table de vérité, écrite à la main depuis
 * .memory-bank/systemPatterns.md §1 et le backlog. Elle est délibérément
 * redondante avec la matrice du service : c'est ce qui la verrouille. Toute
 * modification de la matrice du service doit être répétée ici, à la main,
 * avec la source métier qui la justifie — jamais dérivée du service, sinon le
 * test se contenterait de confirmer le code au lieu de le contraindre.
 *
 * N'utilise pas RefreshDatabase : transitionTo() passe par
 * AuditChain::transaction(), qui refuse de s'exécuter depuis une transaction
 * déjà ouverte (voir la docstring de AuditChain).
 */

/** @return array<string, array<string, list<string>>> */
function matriceAttendue(): array
{
    return [
        // Enregistrement récent : la fenêtre de contestation de 30 jours.
        'V-PRV' => [
            'V-ACT' => ['system'],      // ST-0402 : bascule automatique à J+30
            'V-LOC' => ['owner'],       // ST-0702 : marquage flotte du loueur
            'V-VOL' => ['owner'],       // ST-0604 : vol en un geste (OTP)
            'V-LIT' => ['claim'],       // ST-0503 : gel dès recevabilité
            'V-FDV' => ['owner'],       // ST-0606 : fin de vie déclarée
        ],
        'V-ACT' => [
            'V-LOC' => ['owner'],
            'V-VTE' => ['transfer'],    // ST-0601 : initiation du transfert
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
            'V-FDV' => ['owner'],
        ],
        'V-LOC' => [
            'V-ACT' => ['owner'],       // ST-0702 : démarquage
            'V-VTE' => ['transfer'],    // revente d'un véhicule de flotte
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
            'V-FDV' => ['owner'],
        ],
        // Transfert en cours : confirmé (double OTP), annulé par le vendeur
        // avant confirmation, ou expiré à J+7 — ces deux derniers chemins
        // ramènent le bien à son statut antérieur, d'où les trois cibles.
        'V-VTE' => [
            'V-ACT' => ['transfer', 'owner', 'system'],
            'V-PRV' => ['owner', 'system'],
            'V-LOC' => ['owner', 'system'],
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
        ],
        // Volé déclaré : levée par le MÊME détenteur (systemPatterns §1). Une
        // réclamation reste recevable sur un bien volé — c'est précisément le
        // cas d'un propriétaire dépossédé qui se manifeste.
        'V-VOL' => [
            'V-ACT' => ['owner'],
            'V-LIT' => ['claim'],
        ],
        // Litige : seule une décision d'arbitrage motivée en sort. Le maintien
        // (« litige non tranché », écart < 20 pts) est journalisé comme une
        // transition vers lui-même pour laisser une trace de la décision.
        'V-LIT' => [
            'V-ACT' => ['arbitration'],
            'V-LIT' => ['arbitration'],
        ],
        // Hors d'usage : ST-0606, l'identifiant n'est libéré que sur décision
        // back-office.
        'V-FDV' => [
            'V-ACT' => ['backoffice'],
        ],
    ];
}

beforeEach(function (): void {
    if (! Schema::hasTable('asset_status_history')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerBiens();
});
afterEach(fn () => nettoyerBiens());

function nettoyerBiens(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'asset_status_history', 'assets', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienAuStatut(LifeStatus $statut): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'identifier_normalized' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'active_flag' => 1,
        'attributes' => ['brand_model' => 'Yamaha Crux'],
        'trust_level' => TrustLevel::Declared,
        'life_status' => $statut,
        'registered_at' => now(),
    ]);
}

it('autorise exactement les transitions de la matrice, et aucune autre', function (): void {
    $attendue = matriceAttendue();
    $ecarts = [];

    foreach (LifeStatus::cases() as $depuis) {
        foreach (LifeStatus::cases() as $vers) {
            foreach (TriggerType::cases() as $declencheur) {
                $devraitEtreAutorisee = in_array(
                    $declencheur->value,
                    $attendue[$depuis->value][$vers->value] ?? [],
                    true,
                );

                $estAutorisee = StatusTransitionService::isAllowed($depuis, $vers, $declencheur);

                if ($estAutorisee !== $devraitEtreAutorisee) {
                    $ecarts[] = sprintf(
                        '%s → %s (%s) : attendu %s, obtenu %s',
                        $depuis->value,
                        $vers->value,
                        $declencheur->value,
                        $devraitEtreAutorisee ? 'autorisée' : 'refusée',
                        $estAutorisee ? 'autorisée' : 'refusée',
                    );
                }
            }
        }
    }

    expect($ecarts)->toBe([]);
});

it('refuse une transition avec le bon déclencheur mais depuis un statut interdit', function (): void {
    // Un bien hors d'usage ne repart pas en location sur simple décision du
    // propriétaire : seul le back-office libère un identifiant (ST-0606).
    $bien = bienAuStatut(LifeStatus::EndOfLife);

    expect(fn () => app(StatusTransitionService::class)
        ->transitionTo($bien, LifeStatus::Rented, TriggerType::Owner, $bien->owner_user_id))
        ->toThrow(TransitionInterditeException::class);
});

it('refuse le bon couple de statuts déclenché par le mauvais acteur', function (): void {
    // Le gel en litige n'appartient qu'à la recevabilité d'une réclamation :
    // un propriétaire ne peut pas geler son propre bien pour bloquer une
    // procédure en cours.
    $bien = bienAuStatut(LifeStatus::Active);

    expect(fn () => app(StatusTransitionService::class)
        ->transitionTo($bien, LifeStatus::Disputed, TriggerType::Owner, $bien->owner_user_id))
        ->toThrow(TransitionInterditeException::class);
});

it('laisse le bien intact quand la transition est refusée', function (): void {
    $bien = bienAuStatut(LifeStatus::Active);

    try {
        app(StatusTransitionService::class)
            ->transitionTo($bien, LifeStatus::EndOfLife, TriggerType::System);
    } catch (TransitionInterditeException) {
        // Attendu.
    }

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active)
        ->and(AssetStatusHistory::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('écrit une ligne d\'historique pour toute transition acceptée', function (): void {
    $bien = bienAuStatut(LifeStatus::Active);

    app(StatusTransitionService::class)->transitionTo(
        $bien,
        LifeStatus::Stolen,
        TriggerType::Owner,
        $bien->owner_user_id,
        'Déclaration de vol par le détenteur',
    );

    $ligne = AssetStatusHistory::where('asset_id', $bien->id)->sole();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Stolen)
        ->and($ligne->from_status)->toBe(LifeStatus::Active)
        ->and($ligne->to_status)->toBe(LifeStatus::Stolen)
        ->and($ligne->trigger_type)->toBe(TriggerType::Owner)
        ->and($ligne->actor_user_id)->toBe($bien->owner_user_id)
        ->and($ligne->reason)->toBe('Déclaration de vol par le détenteur');
});

it('journalise la transition dans la chaîne d\'audit, dans la même transaction', function (): void {
    $bien = bienAuStatut(LifeStatus::Active);

    app(StatusTransitionService::class)
        ->transitionTo($bien, LifeStatus::Stolen, TriggerType::Owner, $bien->owner_user_id);

    $entree = AuditLog::sole();

    expect($entree->action)->toBe('asset.status_transition')
        ->and($entree->entity_type)->toBe('asset')
        ->and($entree->entity_id)->toBe($bien->id)
        ->and($entree->payload)->toMatchArray([
            'from_status' => 'V-ACT',
            'to_status' => 'V-VOL',
            'trigger_type' => 'owner',
        ]);
});

it('horodate l\'historique et la chaîne d\'audit de façon cohérente', function (): void {
    // Les deux tables reçoivent la même action : leurs horodatages doivent
    // pouvoir être rapprochés dans un export d'arbitrage, donc ne jamais
    // dépendre du fuseau de la session (colonnes DATETIME, connexion UTC).
    $bien = bienAuStatut(LifeStatus::Active);

    app(StatusTransitionService::class)
        ->transitionTo($bien, LifeStatus::Stolen, TriggerType::Owner, $bien->owner_user_id);

    $historique = AssetStatusHistory::sole()->created_at;
    $audit = AuditLog::sole()->created_at;

    expect($historique)->not->toBeNull()
        ->and($audit)->not->toBeNull()
        ->and(abs($historique->diffInSeconds($audit)))->toBeLessThanOrEqual(1);
});

it('interdit toute réécriture de l\'historique des statuts', function (): void {
    $bien = bienAuStatut(LifeStatus::Active);

    app(StatusTransitionService::class)
        ->transitionTo($bien, LifeStatus::Stolen, TriggerType::Owner, $bien->owner_user_id);

    $ligne = AssetStatusHistory::sole();

    expect(fn () => $ligne->update(['to_status' => LifeStatus::Active]))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $ligne->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('rejette une transition vers le statut courant hors maintien de litige', function (): void {
    // Sans cette règle, un propriétaire pourrait polluer l'historique public
    // de son bien en répétant une transition neutre.
    $bien = bienAuStatut(LifeStatus::Active);

    expect(fn () => app(StatusTransitionService::class)
        ->transitionTo($bien, LifeStatus::Active, TriggerType::Owner, $bien->owner_user_id))
        ->toThrow(TransitionInterditeException::class);
});

it('journalise le maintien d\'un litige non tranché comme une décision', function (): void {
    // Grille d'arbitrage : écart < 20 points → « litige non tranché », le bien
    // reste gelé mais la décision doit laisser une trace.
    $bien = bienAuStatut(LifeStatus::Disputed);

    app(StatusTransitionService::class)->transitionTo(
        $bien,
        LifeStatus::Disputed,
        TriggerType::Arbitration,
        null,
        'Litige non tranché : écart de preuves inférieur à 20 points',
    );

    $ligne = AssetStatusHistory::sole();

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Disputed)
        ->and($ligne->from_status)->toBe(LifeStatus::Disputed)
        ->and($ligne->to_status)->toBe(LifeStatus::Disputed)
        ->and($ligne->trigger_type)->toBe(TriggerType::Arbitration);
});
