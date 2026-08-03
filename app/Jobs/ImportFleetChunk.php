<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FleetImport;
use App\Services\FleetService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traite une tranche d'un import de flotte (ST-0702).
 *
 * DÉCOUPÉ, ET C'EST TOUT L'INTÉRÊT. Chaque enregistrement prend le verrou nommé
 * de la chaîne d'audit, dont le plafond mesuré est d'une quinzaine d'actions
 * simultanées. Un unique travail traitant cinq mille lignes tiendrait le
 * travailleur — et disputerait ce verrou — pendant plusieurs minutes : les
 * enregistrements, déclarations de vol et transferts de tous les autres
 * utilisateurs seraient rejetés pendant ce temps. Entre deux tranches, le
 * verrou est rendu et les actions ordinaires passent.
 *
 * IDEMPOTENT PAR CONSTRUCTION. Une tranche rejouée après incident retente ses
 * lignes : celles déjà entrées ressortent en doublon, comptées comme ignorées
 * et non comme erreurs. Un import interrompu se reprend donc sans rien
 * dupliquer ni rien perdre.
 */
final class ImportFleetChunk implements ShouldQueue
{
    use Queueable;

    /**
     * Lignes par tranche. Assez pour avancer vite, assez peu pour rendre la
     * main souvent : c'est le verrou d'audit qui fixe l'ordre de grandeur.
     */
    public const TAILLE = 50;

    public int $tries = 3;

    public function __construct(
        public readonly int $importId,
        public readonly int $offset,
    ) {
        $this->onQueue('fleet-imports');
        $this->afterCommit();
    }

    public function handle(FleetService $flotte): void
    {
        $import = FleetImport::find($this->importId);

        if (! $import instanceof FleetImport || $import->isFinished()) {
            return;
        }

        $flotte->processChunk($import, $this->offset, self::TAILLE);
    }
}
