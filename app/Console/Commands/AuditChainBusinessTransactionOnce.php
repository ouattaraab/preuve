<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActorType;
use App\Services\AuditChain;
use Illuminate\Console\Command;

/**
 * Point d'entrée dédié au test de concurrence de
 * AuditChain::transaction() (tests/Feature/AuditChainTransactionConcurrencyTest.php) :
 * une seule invocation, dans un processus PHP indépendant, simulant un
 * travail métier (lecture + attente) et son entrée d'audit dans une seule
 * transaction — le scénario précisément démontré par une revue comme faisant
 * perdre des entrées d'audit quand append() est appelée depuis une
 * transaction métier englobante (round 3 de correction).
 */
final class AuditChainBusinessTransactionOnce extends Command
{
    protected $signature = 'audit-chain:business-transaction-once {entityId : Identifiant utilisé comme entity_id et comme marqueur dans le payload}';

    protected $description = "Exécute un travail métier simulé et son entrée d'audit dans une transaction (usage : tests de concurrence uniquement)";

    public function handle(AuditChain $chain): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error(
                'Commande réservée aux tests de concurrence : elle écrit une vraie entrée dans une '.
                "chaîne d'audit inaltérable et refuse de s'exécuter hors des environnements local/testing."
            );

            return self::FAILURE;
        }

        $entityId = (int) $this->argument('entityId');

        $chain->transaction(function () use ($entityId): array {
            // Travail métier simulé : lecture puis attente, comme le
            // scénario « lisant, appelant append(), attendant, puis
            // committant » démontré par la revue.
            usleep(50_000);

            return [
                'result' => null,
                'actorType' => ActorType::System,
                'actorId' => null,
                'action' => 'test.business_transaction',
                'entityType' => 'asset',
                'entityId' => $entityId,
                'payload' => ['worker' => $entityId],
            ];
        });

        return self::SUCCESS;
    }
}
