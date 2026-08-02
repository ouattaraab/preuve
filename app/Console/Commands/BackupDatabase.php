<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sauvegarde chiffrée de la base (ST-0904).
 *
 * LA SAUVEGARDE EST CHIFFRÉE AVANT DE QUITTER LA MACHINE. Un dump de la base de
 * PREUVE contient des numéros de téléphone, des empreintes de pièces
 * d'identité et l'intégralité de la chaîne d'audit : le déposer en clair sur un
 * stockage tiers reviendrait à annuler tout ce que la plateforme protège par
 * ailleurs.
 *
 * ELLE EST DÉPOSÉE HORS DE LA MACHINE qui l'a produite. Une sauvegarde qui vit
 * sur le disque qu'elle sauvegarde ne protège de rien — ni d'une panne, ni
 * d'un effacement volontaire.
 *
 * ELLE NE PROUVE RIEN TANT QU'ELLE N'A PAS ÉTÉ RESTAURÉE. La commande le dit à
 * chaque exécution : produire des sauvegardes jamais éprouvées entretient une
 * confiance qui se révèle fausse le seul jour où elle compte.
 */
final class BackupDatabase extends Command
{
    protected $signature = 'preuve:backup {--keep=30 : Sauvegardes conservées}';

    protected $description = 'Produit une sauvegarde chiffrée de la base et la dépose hors machine';

    public function handle(): int
    {
        $disque = $this->disk();

        if ($disque === null) {
            $this->components->error(
                'Aucun disque de sauvegarde configuré (preuve.backup.disk) : une sauvegarde qui reste sur '.
                'la machine qu\'elle sauvegarde ne protège de rien.'
            );

            return self::FAILURE;
        }

        try {
            $dump = $this->dump();
        } catch (Throwable $e) {
            $this->components->error('Sauvegarde impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $nom = 'preuve-'.now()->format('Y-m-d-His').'.sql.enc';

        // Chiffrement avec APP_KEY : la clé ne quitte pas la machine, la
        // sauvegarde si.
        Storage::disk($disque)->put('backups/'.$nom, Crypt::encryptString($dump));

        $this->prune($disque);

        $this->components->info(sprintf(
            'Sauvegarde chiffrée déposée : %s (%s Ko).',
            $nom,
            number_format(strlen($dump) / 1024, 0, ',', ' '),
        ));

        $this->components->warn(
            'Une sauvegarde jamais restaurée n\'est pas une sauvegarde. Éprouvez la restauration sur un '.
            'environnement vierge, puis vérifiez la chaîne : php artisan preuve:verify-audit-chain'
        );

        return self::SUCCESS;
    }

    /** @throws RuntimeException */
    private function dump(): string
    {
        $connexion = config('database.default');
        $config = config('database.connections.'.(is_string($connexion) ? $connexion : 'mariadb'));

        if (! is_array($config)) {
            throw new RuntimeException('Configuration de base introuvable.');
        }

        $processus = new Process([
            $this->mysqldumpBinary(),
            '--host='.(is_string($config['host'] ?? null) ? $config['host'] : '127.0.0.1'),
            '--port='.(is_numeric($config['port'] ?? null) ? (string) $config['port'] : '3306'),
            '--user='.(is_string($config['username'] ?? null) ? $config['username'] : 'root'),
            '--password='.(is_string($config['password'] ?? null) ? $config['password'] : ''),
            // Les déclencheurs d'inaltérabilité d'audit_log font partie du
            // schéma : une restauration sans eux rendrait le journal
            // modifiable sans que personne ne s'en aperçoive.
            '--triggers',
            '--routines',
            '--single-transaction',
            is_string($config['database'] ?? null) ? $config['database'] : 'preuve',
        ]);

        $processus->setTimeout(600);
        $processus->run();

        if (! $processus->isSuccessful()) {
            throw new RuntimeException(trim($processus->getErrorOutput()) ?: 'mysqldump a échoué.');
        }

        return $processus->getOutput();
    }

    /** Ne garde que les N dernières : un stockage saturé ne sauvegarde plus rien. */
    private function prune(string $disque): void
    {
        $garder = max(1, (int) $this->option('keep'));
        $fichiers = Storage::disk($disque)->files('backups');

        rsort($fichiers);

        foreach (array_slice($fichiers, $garder) as $ancien) {
            if (is_string($ancien)) {
                Storage::disk($disque)->delete($ancien);
            }
        }
    }

    private function disk(): ?string
    {
        $disque = config('preuve.backup.disk');

        return is_string($disque) && $disque !== '' ? $disque : null;
    }

    private function mysqldumpBinary(): string
    {
        $chemin = config('preuve.backup.mysqldump');

        return is_string($chemin) && $chemin !== '' ? $chemin : 'mysqldump';
    }
}
