<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActorType;
use App\Services\AuditChain;
use Illuminate\Console\Command;

/**
 * Point d'entrée dédié aux tests de concurrence de la chaîne d'audit
 * (tests/Feature/AuditChainConcurrencyTest.php) : un seul append(), exécuté
 * dans un processus PHP indépendant, pour mettre à l'épreuve le verrou de
 * AuditChain::append() sous une écriture concurrente réelle — chose qu'un
 * simple bouclage séquentiel dans un seul processus ne peut pas vérifier.
 */
final class AuditChainAppendOnce extends Command
{
    protected $signature = 'audit-chain:append-once {entityId : Identifiant utilisé comme entity_id et comme marqueur dans le payload}';

    protected $description = "Insère une unique entrée d'audit (usage : tests de concurrence uniquement)";

    public function handle(AuditChain $chain): int
    {
        $entityId = (int) $this->argument('entityId');

        $chain->append(ActorType::System, null, 'test.concurrent', 'asset', $entityId, ['worker' => $entityId]);

        return self::SUCCESS;
    }
}
