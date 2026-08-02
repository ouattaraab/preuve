<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Clôt les transferts dont la fenêtre de 7 jours est écoulée (ST-0601).
 *
 * Un transfert laissé ouvert indéfiniment maintiendrait le bien en « Transfert
 * en cours » — statut qui avertit les acheteurs qu'une cession est en route.
 * Un vendeur pourrait ainsi geler la revendabilité de son propre bien, ou
 * pire, un acheteur de mauvaise foi retenir le bien d'un vendeur en ne
 * confirmant jamais.
 *
 * Borné et sans interruption sur échec, comme les autres passages : chaque
 * retour de statut prend le verrou nommé de la chaîne d'audit.
 */
final class ExpireTransfers extends Command
{
    protected $signature = 'preuve:expire-transfers {--limit=200 : Nombre maximal de clôtures par passage}';

    protected $description = 'Clôt les transferts non confirmés dans les 7 jours';

    public function handle(TransferService $transfers): int
    {
        $limite = max(1, (int) $this->option('limit'));

        $echus = Transfer::query()
            ->whereIn('status', [TransferStatus::Initiated->value, TransferStatus::BuyerConfirmed->value])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limite)
            ->get();

        $clos = 0;

        foreach ($echus as $transfert) {
            try {
                $transfers->expire($transfert);
                $clos++;
            } catch (Throwable $e) {
                $this->components->warn(
                    "Transfert #{$transfert->id} non clos (".$e::class.') : repris au passage suivant.'
                );
            }
        }

        $this->components->info("{$clos} transfert(s) expiré(s).");

        if ($echus->count() === $limite) {
            $this->components->warn(
                "Limite de {$limite} atteinte : d'autres transferts échus restent à clore."
            );
        }

        return self::SUCCESS;
    }
}
