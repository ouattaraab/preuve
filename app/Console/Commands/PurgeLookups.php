<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purge des consultations de plus de 12 mois (ST-0304, politique ARTCI).
 *
 * Ce n'est pas de l'entretien de confort mais une obligation : le journal ne
 * contient que des empreintes salées, mais leur accumulation indéfinie
 * reconstituerait une matière exploitable — d'autant que qui détiendrait
 * APP_KEY pourrait balayer l'espace IPv4 pour lever le sel. Les conserver
 * moins longtemps est la seule protection réelle.
 *
 * La suppression se fait par lots : un DELETE unique sur une table de plusieurs
 * millions de lignes tiendrait un verrou trop longtemps pour un mutualisé.
 */
final class PurgeLookups extends Command
{
    protected $signature = 'preuve:purge-lookups {--months=12 : Ancienneté au-delà de laquelle purger}';

    protected $description = 'Purge les consultations de plus de 12 mois (politique ARTCI)';

    private const BATCH_SIZE = 1000;

    public function handle(): int
    {
        $mois = max(1, (int) $this->option('months'));
        $limite = now()->subMonths($mois);
        $supprimees = 0;

        do {
            // Requête brute plutôt qu'Eloquent : la suppression n'a besoin
            // d'aucun modèle, et le constructeur de requêtes rend un compte
            // d'un type sûr.
            $lot = DB::table('lookups')
                ->where('created_at', '<', $limite)
                ->limit(self::BATCH_SIZE)
                ->delete();

            $supprimees += $lot;
        } while ($lot > 0);

        $this->components->info(
            "{$supprimees} consultation(s) antérieure(s) au {$limite->format('d/m/Y')} purgée(s)."
        );

        return self::SUCCESS;
    }
}
