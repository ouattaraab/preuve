<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpsReporter;
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

    public function __construct(private readonly OpsReporter $rapports)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $disque = $this->disk();

        if ($disque === null) {
            $message = 'Aucun disque de sauvegarde configuré (preuve.backup.disk) : une sauvegarde qui reste '.
                'sur la machine qu\'elle sauvegarde ne protège de rien.';

            $this->components->error($message);
            $this->rapports->alert('Sauvegarde de la base', $message, true);

            return self::FAILURE;
        }

        try {
            $dump = $this->dump();
        } catch (Throwable $e) {
            $this->components->error('Sauvegarde impossible : '.$e->getMessage());

            // Une sauvegarde absente ne se voit pas : tout continue de
            // fonctionner, et c'est le jour de la panne qu'on l'apprend.
            $this->rapports->alert(
                'Sauvegarde de la base',
                'Aucune sauvegarde n\'a pu être produite cette nuit.'.PHP_EOL.PHP_EOL.$e->getMessage(),
                true,
            );

            return self::FAILURE;
        }

        // LA SAUVEGARDE EST ÉCRITE, MAIS ON DIT CE QU'ELLE VAUT.
        //
        // Configurée sur le même disque que la base, elle protège d'une
        // corruption logique — une migration ratée, une suppression de trop —
        // et de RIEN d'autre. Le jour où le serveur est perdu, elle l'est avec
        // lui. La commande des pièces refuse carrément ce montage ; ici on ne
        // refuse pas, parce qu'une copie locale a une vraie valeur contre les
        // fautes de manipulation. Mais se taire ferait passer pour un plan de
        // reprise ce qui n'en est pas un, et c'est ainsi qu'on découvre le
        // problème le jour où il n'est plus réparable.
        if ($this->resteSurPlace($disque)) {
            $message = sprintf(
                'La sauvegarde est écrite sur le disque « %s », celui-là même où vit la plateforme. '.
                'Elle protège d\'une corruption logique, pas d\'une perte du serveur. '.
                'Renseignez preuve.backup.disk sur un stockage distant (s3, r2).',
                $disque,
            );

            $this->components->warn($message);
            $this->rapports->alert('Sauvegarde de la base', $message, true);
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

        return $this->withoutDefiners($processus->getOutput());
    }

    /**
     * Retire les clauses `DEFINER` des déclencheurs et des routines.
     *
     * SANS CELA, LA SAUVEGARDE N'EST RESTAURABLE QUE PAR LE COMPTE QUI L'A
     * PRODUITE. `mysqldump` fige dans chaque déclencheur le compte qui l'a
     * créé (`DEFINER=`u...`@`127.0.0.1``) ; les recréer sous un autre compte
     * exige le privilège `SET USER`, qu'un hébergement mutualisé n'accorde
     * jamais. La restauration échoue alors sur :
     *
     *     ERROR 1227 (42000): Access denied; you need (at least one of) the
     *     SET USER privilege(s) for this operation.
     *
     * Constaté le 03/08/2026 au premier exercice mené sur l'hébergement cible.
     * Le défaut est silencieux au possible : la sauvegarde se produit chaque
     * nuit, se chiffre, se dépose, et pèse le bon nombre d'octets. Rien ne
     * distingue une archive restaurable d'une archive qui ne l'est pas —
     * jusqu'au jour où l'on essaie, c'est-à-dire le pire.
     *
     * Sans clause `DEFINER`, les déclencheurs se recréent au nom du compte qui
     * restaure : ce qu'on veut, et la seule chose qui reste possible quand le
     * compte d'origine a disparu avec le serveur.
     */
    public function withoutDefiners(string $dump): string
    {
        // Forme commentée par version : /*!50017 DEFINER=`u`@`h` */
        $sans = preg_replace('/\/\*!5001[37] DEFINER=[^*]*\*\/\s*/', '', $dump);

        // Forme nue, hors commentaire de version.
        return preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $sans ?? $dump) ?? $dump;
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

    /**
     * Vrai quand la « sauvegarde » vit sur le même disque que ce qu'elle
     * sauvegarde.
     *
     * On compare les RACINES et non les noms : deux disques nommés
     * différemment peuvent pointer le même dossier, et c'est exactement le
     * genre de configuration qu'on croit sûre.
     */
    private function resteSurPlace(string $disque): bool
    {
        $racine = config('filesystems.disks.'.$disque.'.root');
        $local = config('filesystems.disks.local.root');

        return $disque === 'local'
            || (is_string($racine) && is_string($local) && realpath($racine) === realpath($local));
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
