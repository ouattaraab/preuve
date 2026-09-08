<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Exercice de restauration (ST-0904).
 *
 * UNE SAUVEGARDE JAMAIS RESTAURÉE EST UNE HYPOTHÈSE. Produire des archives
 * chiffrées et les compter chaque matin entretient une confiance qui ne se
 * vérifie que le jour où elle compte — et où il est trop tard pour découvrir
 * qu'elle était fausse. Cette commande existe pour transformer l'hypothèse en
 * constat, et pour être relancée périodiquement, pas une fois.
 *
 * ELLE NE TOUCHE JAMAIS LA BASE EN SERVICE. La restauration se fait dans une
 * base d'exercice créée pour l'occasion, dont le nom est vérifié différent de
 * celui de la base configurée avant la moindre écriture. Un outil de
 * restauration capable d'écraser la production est un incident qui attend son
 * heure : c'est précisément le jour d'une panne, sous pression, qu'on le
 * lancerait de travers.
 *
 * ELLE VÉRIFIE LES PROTECTIONS, PAS SEULEMENT LES DONNÉES. C'est le point que
 * manque un contrôle naïf : un dump restauré sans ses déclencheurs rend une
 * base d'apparence parfaite, complète, cohérente — et dont le journal d'audit
 * est silencieusement modifiable. Constater que les lignes sont revenues ne
 * dit rien de cela. L'exercice tente donc réellement un UPDATE et un DELETE sur
 * `audit_log`, et n'est concluant que s'ils échouent tous les deux : un
 * déclencheur présent dans le schéma prouve qu'il a été créé, une écriture
 * refusée prouve qu'il protège.
 */
final class RestoreDrill extends Command
{
    protected $signature = 'preuve:restore-drill
                            {--file= : Sauvegarde à éprouver (par défaut, la plus récente)}
                            {--database= : Base d\'exercice déjà créée, à utiliser telle quelle}
                            {--keep : Conserve la base d\'exercice pour inspection}';

    protected $description = "Restaure une sauvegarde dans une base d'exercice et vérifie ce qui en ressort";

    private const CONNEXION = 'restore_drill';

    /** Vrai si la base d'exercice a été créée ici — donc à supprimer ici. */
    private bool $baseCreee = false;

    public function handle(AuditChain $chain): int
    {
        $fournie = $this->option('database');
        $fournie = is_string($fournie) && $fournie !== '' ? $fournie : null;

        // Le refus de la production portait sur la CRÉATION et la SUPPRESSION
        // de bases, pas sur l'exercice lui-même. Avec une base préparée par
        // l'exploitant, l'outil n'en crée aucune et n'en supprime aucune : la
        // sûreté ne vient plus de l'environnement mais des trois contrôles qui
        // suivent — nom différent de la base en service, base obligatoirement
        // vide, et rien n'est jamais supprimé qui n'ait été créé ici.
        //
        // Maintenir le refus aurait rendu la dette « restauration jamais
        // éprouvée sur la cible » définitivement inclosable, ce qui est le
        // contraire du but.
        if (app()->isProduction() && $fournie === null) {
            $this->components->error(
                "En production, cet exercice exige une base préparée à l'avance : il ne doit pas pouvoir en ".
                'créer ni en supprimer ici. Créez une base vide et passez --database=nom.'
            );

            return self::FAILURE;
        }

        $disque = config('preuve.backup.disk');

        if (! is_string($disque) || $disque === '') {
            $this->components->error('Aucun disque de sauvegarde configuré (preuve.backup.disk).');

            return self::FAILURE;
        }

        $fichier = $this->pickBackup($disque);

        if ($fichier === null) {
            $this->components->error("Aucune sauvegarde à éprouver sur le disque « {$disque} ».");

            return self::FAILURE;
        }

        $this->components->info("Sauvegarde éprouvée : {$fichier}");

        try {
            $dump = Crypt::decryptString((string) Storage::disk($disque)->get($fichier));
        } catch (Throwable $e) {
            // Une archive indéchiffrable est le pire des cas : elle occupe la
            // place d'une sauvegarde, et n'en est pas une. APP_KEY a-t-elle
            // tourné sans que les anciennes archives soient reprises ?
            $this->components->error(
                'Archive indéchiffrable ('.$e::class.'). Une sauvegarde qu\'on ne peut pas ouvrir n\'en est '.
                'pas une : vérifiez qu\'APP_KEY n\'a pas changé depuis son dépôt.'
            );

            return self::FAILURE;
        }

        $base = $fournie ?? $this->drillDatabaseName();
        $echecs = [];

        try {
            $this->prepareDatabase($base, $fournie !== null);
            $this->loadDump($base, $dump);
            $this->connect($base);

            $echecs = $this->inspect($chain);
        } catch (Throwable $e) {
            $this->components->error('Restauration impossible : '.$e->getMessage());

            $this->dropDatabase($base);

            return self::FAILURE;
        }

        if ($this->option('keep') === true) {
            $this->components->warn("Base d'exercice conservée : {$base}. Supprimez-la après inspection.");
        } else {
            $this->dropDatabase($base);
        }

        if ($echecs !== []) {
            foreach ($echecs as $echec) {
                $this->components->error($echec);
            }

            $this->components->error(
                "RESTAURATION NON CONCLUANTE. Cette sauvegarde ne vous rendrait pas la plateforme en l'état."
            );

            return self::FAILURE;
        }

        $this->components->info(
            'Restauration concluante : données, protections du journal et chaîne d\'audit vérifiées.'
        );

        return self::SUCCESS;
    }

    /**
     * Contrôles menés sur la base restaurée.
     *
     * @return list<string> anomalies constatées
     */
    private function inspect(AuditChain $chain): array
    {
        $echecs = [];

        // 1. Y a-t-il quelque chose ? Une archive syntaxiquement valide d'une
        //    base vide se restaure parfaitement et ne rend rien.
        $biens = DB::connection(self::CONNEXION)->table('assets')->count();
        $entrees = DB::connection(self::CONNEXION)->table('audit_log')->count();

        $this->components->twoColumnDetail('Biens restaurés', (string) $biens);
        $this->components->twoColumnDetail("Entrées d'audit restaurées", (string) $entrees);

        // 2. LE contrôle que manque une vérification naïve : les déclencheurs
        //    d'inaltérabilité ont-ils survécu au voyage ? On ne se contente pas
        //    de les chercher dans le schéma, on tente d'écrire.
        if ($entrees > 0) {
            foreach ($this->appendOnlyProbes() as $libelle => $sql) {
                if ($this->succeeds($sql)) {
                    $echecs[] = "Le journal d'audit restauré accepte un {$libelle} : les déclencheurs ".
                        "d'inaltérabilité n'ont pas été restaurés, et personne ne s'en apercevrait.";
                } else {
                    $this->components->twoColumnDetail("Refus d'un {$libelle} sur audit_log", '<fg=green>oui</>');
                }
            }
        } else {
            $echecs[] = "Le journal d'audit restauré est vide : il n'y a rien à protéger, donc rien à prouver.";
        }

        // 3. Cohérence interne de la chaîne, rejouée sur la base restaurée.
        $interne = $chain->verify(self::CONNEXION);

        if ($interne['valid']) {
            $this->components->twoColumnDetail('Cohérence interne de la chaîne', '<fg=green>intacte</>');
        } else {
            $echecs[] = "La chaîne restaurée est rompue à l'entrée #".((string) $interne['broken_at']).'.';
        }

        // 4. Confrontation aux ancrages. La cohérence interne ne prouve rien
        //    seule : l'algorithme est public, une chaîne reconstruite la
        //    passerait. Seule la tête publiée dehors fait foi.
        $echecs = array_merge($echecs, $this->confrontAnchors());

        return $echecs;
    }

    /** @return array<string, string> */
    private function appendOnlyProbes(): array
    {
        return [
            'UPDATE' => "UPDATE audit_log SET action = 'sonde.restauration' ORDER BY id LIMIT 1",
            'DELETE' => 'DELETE FROM audit_log ORDER BY id LIMIT 1',
        ];
    }

    /** Vrai si l'écriture est passée — c'est-à-dire si la protection a disparu. */
    private function succeeds(string $sql): bool
    {
        $connexion = DB::connection(self::CONNEXION);

        try {
            // En transaction annulée : si la protection a bel et bien sauté, la
            // sonde ne doit pas laisser derrière elle une base d'exercice
            // modifiée dont on tirerait ensuite de fausses conclusions.
            $connexion->beginTransaction();
            $connexion->statement($sql);
            $connexion->rollBack();

            return true;
        } catch (Throwable) {
            try {
                $connexion->rollBack();
            } catch (Throwable) {
                // Le déclencheur a pu interrompre la transaction lui-même.
            }

            return false;
        }
    }

    /**
     * Compare chaque ancrage à ce que la base restaurée raconte.
     *
     * @return list<string>
     */
    private function confrontAnchors(): array
    {
        $connexion = DB::connection(self::CONNEXION);
        $echecs = [];
        $verifies = 0;

        foreach ($connexion->table('audit_anchors')->where('status', 'anchored')->orderBy('id')->cursor() as $ancrage) {
            $verifies++;
            $ancrage = (array) $ancrage;
            $headId = $ancrage['head_id'] ?? null;

            if (! is_numeric($headId)) {
                continue;
            }

            $headId = (int) $headId;

            $entree = $connexion->table('audit_log')->where('id', $headId)->first();

            if ($entree === null) {
                $echecs[] = "L'entrée de tête ancrée (#{$headId}) manque dans la base restaurée.";

                continue;
            }

            $attendu = $ancrage['head_chain_hash'] ?? null;
            $constate = ((array) $entree)['chain_hash'] ?? null;

            if (! is_string($attendu) || ! is_string($constate) || ! hash_equals($attendu, $constate)) {
                $echecs[] = "La tête restaurée ne correspond pas à l'empreinte ancrée le ".
                    (is_scalar($ancrage['created_at'] ?? null) ? (string) $ancrage['created_at'] : '?').'.';
            }
        }

        if ($verifies === 0) {
            // Pas une anomalie de la restauration, mais une limite du constat :
            // sans ancrage, la sauvegarde peut être fidèle à une base qui avait
            // déjà été réécrite.
            $this->components->warn(
                'Aucun ancrage dans la sauvegarde : la restauration est fidèle à ce qui a été sauvegardé, '.
                "mais rien ne prouve que ce qui a été sauvegardé n'avait pas déjà été réécrit."
            );
        } else {
            $this->components->twoColumnDetail(
                'Ancrages confrontés',
                $echecs === [] ? "<fg=green>{$verifies} concordant(s)</>" : (string) $verifies,
            );
        }

        return $echecs;
    }

    private function pickBackup(string $disque): ?string
    {
        $demande = $this->option('file');

        if (is_string($demande) && $demande !== '') {
            return Storage::disk($disque)->exists($demande) ? $demande : null;
        }

        $fichiers = [];

        foreach (Storage::disk($disque)->files('backups') as $chemin) {
            if (is_string($chemin) && str_ends_with($chemin, '.sql.enc')) {
                $fichiers[] = $chemin;
            }
        }

        rsort($fichiers);

        return $fichiers[0] ?? null;
    }

    /**
     * Nom de la base d'exercice, horodaté pour que deux exercices ne se
     * marchent pas dessus.
     */
    private function drillDatabaseName(): string
    {
        return 'preuve_restore_drill_'.now()->format('Ymd_His');
    }

    /**
     * Prépare la base d'exercice.
     *
     * L'exercice N'EXIGE PAS que le compte applicatif puisse créer des bases.
     * Il ne le doit pas : ce droit lui servirait une fois par trimestre et
     * resterait ouvert le reste du temps, sur le compte qu'un attaquant
     * atteint en premier. Deux voies, donc — des identifiants d'exploitation
     * distincts, ou une base préparée à l'avance et passée en argument.
     *
     * @throws RuntimeException
     */
    private function prepareDatabase(string $base, bool $fournie): void
    {
        $enService = DB::connection()->getDatabaseName();

        // LA garde. Sans elle, une erreur de configuration ferait de cet
        // exercice l'outil qui écrase la base qu'il prétend protéger.
        if ($base === $enService) {
            throw new RuntimeException(
                "La base d'exercice porterait le nom de la base en service ({$enService}) : exercice annulé."
            );
        }

        $this->connect($base);

        if ($fournie) {
            // Base préparée par l'exploitant : on n'y touche ni pour la créer,
            // ni pour la supprimer. Mais elle doit être vide — restaurer
            // par-dessus des tables existantes rendrait un mélange dont on ne
            // pourrait rien conclure.
            if (DB::connection(self::CONNEXION)->getSchemaBuilder()->getTableListing() !== []) {
                throw new RuntimeException("La base d'exercice « {$base} » n'est pas vide.");
            }

            return;
        }

        try {
            DB::connection(self::CONNEXION.'_admin')
                ->statement("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Création de la base d'exercice refusée. C'est le comportement attendu : le compte applicatif ".
                "ne doit pas pouvoir créer de bases. Renseignez des identifiants d'exploitation ".
                '(PREUVE_RESTORE_DB_USERNAME / PREUVE_RESTORE_DB_PASSWORD), ou créez une base vide et '.
                'passez-la en argument : --database=preuve_drill. Détail : '.$e->getMessage()
            );
        }

        $this->baseCreee = true;
        DB::purge(self::CONNEXION);
    }

    private function dropDatabase(string $base): void
    {
        if (! $this->baseCreee) {
            return;
        }

        try {
            DB::purge(self::CONNEXION);
            DB::connection(self::CONNEXION.'_admin')->statement("DROP DATABASE IF EXISTS `{$base}`");
        } catch (Throwable $e) {
            $this->components->warn("Base d'exercice non supprimée ({$base}) : ".$e->getMessage());
        }
    }

    /**
     * Déclare deux connexions à côté de celle en service : l'une vers la base
     * d'exercice, l'autre vers la base courante pour les ordres CREATE/DROP.
     * Toutes deux portent les identifiants d'exploitation, le compte
     * applicatif n'ayant aucun droit sur la base d'exercice.
     *
     * @throws RuntimeException
     */
    private function connect(string $base): void
    {
        $defaut = config('database.default');
        $config = config('database.connections.'.(is_string($defaut) ? $defaut : 'mariadb'));

        if (! is_array($config)) {
            throw new RuntimeException('Configuration de base introuvable.');
        }

        $operateur = $this->connectionConfig();
        $config['username'] = $operateur['username'];
        $config['password'] = $operateur['password'];

        $administration = $config;

        $config['database'] = $base;
        Config::set('database.connections.'.self::CONNEXION, $config);
        Config::set('database.connections.'.self::CONNEXION.'_admin', $administration);

        DB::purge(self::CONNEXION);
        DB::purge(self::CONNEXION.'_admin');
    }

    /** @throws RuntimeException */
    private function loadDump(string $base, string $dump): void
    {
        $config = $this->connectionConfig();

        $processus = new Process([
            $this->mysqlBinary(),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--password='.$config['password'],
            $base,
        ]);

        $processus->setInput($dump);
        $processus->setTimeout(600);
        $processus->run();

        if (! $processus->isSuccessful()) {
            throw new RuntimeException(trim($processus->getErrorOutput()) ?: 'Le chargement du dump a échoué.');
        }
    }

    /** @return array{host: string, port: string, username: string, password: string} */
    private function connectionConfig(): array
    {
        $defaut = config('database.default');
        $config = config('database.connections.'.(is_string($defaut) ? $defaut : 'mariadb'));
        $config = is_array($config) ? $config : [];

        // Identifiants d'exploitation s'ils existent, sinon ceux de
        // l'application — qui suffisent quand la base d'exercice est fournie.
        $utilisateur = config('preuve.backup.restore_username');
        $motDePasse = config('preuve.backup.restore_password');

        return [
            'host' => is_string($config['host'] ?? null) ? $config['host'] : '127.0.0.1',
            'port' => is_numeric($config['port'] ?? null) ? (string) $config['port'] : '3306',
            'username' => is_string($utilisateur) && $utilisateur !== ''
                ? $utilisateur
                : (is_string($config['username'] ?? null) ? $config['username'] : 'root'),
            'password' => is_string($motDePasse) && $motDePasse !== ''
                ? $motDePasse
                : (is_string($config['password'] ?? null) ? $config['password'] : ''),
        ];
    }

    private function mysqlBinary(): string
    {
        $chemin = config('preuve.backup.mysql');

        return is_string($chemin) && $chemin !== '' ? $chemin : 'mysql';
    }
}
