<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Enums\TriggerType;
use App\Exceptions\TransitionInterditeException;
use App\Models\Asset;
use App\Models\AssetStatusHistory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Point de passage unique de tout changement de statut de vie d'un bien
 * (règle métier absolue n° 6). Aucun autre code ne doit écrire `life_status`
 * en base : la matrice ci-dessous est la seule définition des transitions
 * possibles, et chaque transition acceptée écrit sa ligne dans
 * `asset_status_history` ainsi que son entrée de chaîne d'audit, dans la même
 * transaction.
 *
 * La matrice porte deux dimensions, pas une : le couple (statut d'origine,
 * statut d'arrivée) ET l'origine de la transition. « Litige en cours » n'est
 * atteignable que par la recevabilité d'une réclamation, jamais par le
 * propriétaire lui-même — sans quoi un détenteur pourrait geler son propre
 * bien pour bloquer une procédure. De même, un bien ne sort de « Litige en
 * cours » que par une décision d'arbitrage motivée.
 *
 * Sources : .memory-bank/systemPatterns.md §1 et les stories ST-0402, ST-0503,
 * ST-0601, ST-0604, ST-0606, ST-0702 du backlog. La table de vérité est
 * répétée à la main dans tests/BusinessRules/MatriceTransitionsTest.php :
 * c'est elle qui verrouille cette matrice.
 */
final class StatusTransitionService
{
    /**
     * Transitions autorisées : statut d'origine → statut d'arrivée → origines
     * recevables.
     *
     * Trois interdictions méritent d'être lues explicitement, car ce sont des
     * décisions et non des oublis — confirmées le 02/08/2026, voir
     * systemPatterns.md §1 :
     * - « Enregistrement récent » ne peut pas être mis en transfert : la
     *   fenêtre de contestation de 30 jours perdrait son objet si un bien
     *   fraîchement enregistré pouvait changer de mains avant son terme.
     * - « Volé déclaré » ne mène qu'à une levée par le même détenteur ou à un
     *   litige : un bien volé ne se déclare pas hors d'usage, sinon la
     *   déclaration de vol s'effacerait derrière une fin de vie.
     * - « Litige en cours » ne mène nulle part hors arbitrage : ni vol, ni
     *   transfert, ni fin de vie, tant que la propriété est contestée.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const MATRIX = [
        'V-PRV' => [
            'V-ACT' => ['system'],
            'V-LOC' => ['owner'],
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
            'V-FDV' => ['owner'],
        ],
        'V-ACT' => [
            'V-LOC' => ['owner'],
            'V-VTE' => ['transfer'],
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
            'V-FDV' => ['owner'],
        ],
        'V-LOC' => [
            'V-ACT' => ['owner'],
            'V-VTE' => ['transfer'],
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
            'V-FDV' => ['owner'],
        ],
        // Trois sorties de transfert : la confirmation par double OTP, et deux
        // retours au statut antérieur — annulation par le vendeur avant
        // confirmation (owner) ou expiration à J+7 (system).
        'V-VTE' => [
            'V-ACT' => ['transfer', 'owner', 'system'],
            'V-PRV' => ['owner', 'system'],
            'V-LOC' => ['owner', 'system'],
            'V-VOL' => ['owner'],
            'V-LIT' => ['claim'],
        ],
        'V-VOL' => [
            'V-ACT' => ['owner'],
            'V-LIT' => ['claim'],
        ],
        // Le maintien (V-LIT → V-LIT) est la décision « litige non tranché »
        // de la grille d'arbitrage : écart de preuves inférieur à 20 points.
        // Elle ne change rien au statut, mais doit laisser une trace datée.
        'V-LIT' => [
            'V-ACT' => ['arbitration'],
            'V-LIT' => ['arbitration'],
        ],
        'V-FDV' => [
            'V-ACT' => ['backoffice'],
        ],
    ];

    public function __construct(private readonly AuditChain $auditChain) {}

    /**
     * Pure et sans effet de bord : sert autant au service qu'aux couches
     * amont, pour ne proposer à l'utilisateur que des actions réalisables
     * (CT-06 : la friction doit rester proportionnée, un bouton qui échoue
     * n'est pas une friction utile).
     */
    public static function isAllowed(LifeStatus $from, LifeStatus $to, TriggerType $trigger): bool
    {
        return in_array($trigger->value, self::MATRIX[$from->value][$to->value] ?? [], true);
    }

    /**
     * Statuts atteignables depuis l'état courant du bien pour une origine
     * donnée.
     *
     * @return list<LifeStatus>
     */
    public static function allowedTargets(LifeStatus $from, TriggerType $trigger): array
    {
        return array_values(array_filter(
            LifeStatus::cases(),
            fn (LifeStatus $to): bool => self::isAllowed($from, $to, $trigger),
        ));
    }

    /**
     * Applique une transition et la journalise. Le statut d'origine est relu
     * sous verrou de ligne au moment de l'écriture : l'instance passée en
     * argument peut avoir été chargée bien avant, et un transfert ou une
     * déclaration de vol concurrente aurait déjà changé le statut réel.
     *
     * $actorUserId reste nul pour les transitions déclenchées par un job
     * planifié (bascule à J+30, expiration d'un transfert).
     *
     * @throws TransitionInterditeException si la transition ne figure pas dans
     *                                      la matrice
     */
    public function transitionTo(
        Asset $asset,
        LifeStatus $to,
        TriggerType $trigger,
        ?int $actorUserId = null,
        ?string $reason = null,
    ): Asset {
        // Refus rapide, sur le statut courant relu sans verrou : une
        // transition interdite ne doit pas prendre le verrou nommé de la
        // chaîne d'audit. Ce verrou sérialise TOUTES les actions auditées de
        // l'application ; le laisser prendre par des tentatives vouées à
        // l'échec offrirait un déni de service à faible coût. La vérification
        // qui fait autorité reste celle effectuée plus bas, sous verrou.
        $this->assertAllowed($this->currentStatus($asset), $to, $trigger);

        return $this->auditChain->transaction(function () use ($asset, $to, $trigger, $actorUserId, $reason): array {
            $verrouille = Asset::whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            $from = $verrouille->life_status;

            $this->assertAllowed($from, $to, $trigger);

            $horodatage = now()->format('Y-m-d H:i:s');

            $verrouille->update(['life_status' => $to]);

            $historique = AssetStatusHistory::create([
                'asset_id' => $verrouille->id,
                'from_status' => $from,
                'to_status' => $to,
                'trigger_type' => $trigger,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'created_at' => $horodatage,
            ]);

            return [
                'result' => $verrouille,
                'actorType' => $actorUserId === null ? ActorType::System : ActorType::User,
                'actorId' => $actorUserId,
                'action' => 'asset.status_transition',
                'entityType' => 'asset',
                'entityId' => $verrouille->id,
                'payload' => [
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'trigger_type' => $trigger->value,
                    'reason' => $reason,
                    // Rattache l'entrée d'audit à sa ligne d'historique : un
                    // export d'arbitrage doit pouvoir prouver que les deux
                    // décrivent bien la même transition.
                    'status_history_id' => $historique->id,
                ],
            ];
        });
    }

    /**
     * Statut courant tel qu'il est réellement en base, et non tel que
     * l'instance en mémoire le croit.
     */
    private function currentStatus(Asset $asset): LifeStatus
    {
        $statut = DB::table('assets')->where('id', $asset->id)->value('life_status');

        if (! is_string($statut)) {
            throw new RuntimeException(
                'Transition de statut impossible : le bien '.$asset->id.' est introuvable.'
            );
        }

        return LifeStatus::from($statut);
    }

    /** @throws TransitionInterditeException */
    private function assertAllowed(LifeStatus $from, LifeStatus $to, TriggerType $trigger): void
    {
        if (! self::isAllowed($from, $to, $trigger)) {
            throw new TransitionInterditeException($from, $to, $trigger);
        }
    }
}
