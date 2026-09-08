<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DocumentInventory;
use App\Services\DocumentVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Remontage des pièces vers le bucket (ST-0904).
 *
 * IL SE LIT À L'ENVERS DE LA SAUVEGARDE. Celle-ci déduplique : deux pièces
 * identiques ne sont stockées qu'une fois. Le remontage doit donc redéployer un
 * même objet vers plusieurs clés, et c'est pourquoi il parcourt la BASE et non
 * le dépôt de sauvegarde. Parcourir les fichiers sauvegardés ne dirait jamais
 * où chacun doit atterrir — seule la base sait quelle empreinte correspond à
 * quelle clé objet. Les deux sauvegardes ne valent qu'ensemble.
 *
 * IL N'ÉCRASE JAMAIS. Une pièce déjà présente sur le bucket est laissée telle
 * quelle. Un remontage se lance après un sinistre, souvent partiel, souvent
 * dans l'urgence : écraser ce qui a survécu par une version plus ancienne
 * transformerait une restauration en seconde perte. Rien dans cette commande
 * ne détruit — c'est ce qui permet de la relancer sans réfléchir.
 *
 * IL VÉRIFIE AVANT D'ÉCRIRE. Une pièce déchiffrée dont l'empreinte ne
 * correspond pas à celle attendue n'est pas déposée. Écrire un contenu erroné
 * sous une clé attendue serait pire que de la laisser vide : un agent
 * examinerait un document en croyant que c'est celui qui a été versé, et
 * trancherait dessus.
 *
 * IL S'EXÉCUTE EN PRODUCTION, contrairement à l'exercice de restauration. C'est
 * son objet même : rétablir un bucket après sinistre. Sa sûreté ne vient pas
 * d'un refus mais de sa nature — il n'écrase pas, il ne supprime pas.
 */
final class RestoreDocuments extends Command
{
    protected $signature = 'preuve:restore-documents
                            {--limit=500 : Pièces remontées au plus par passage}
                            {--dry-run : Constate ce qui serait remonté, sans rien écrire}';

    protected $description = 'Remonte les pièces sauvegardées vers le bucket de documents';

    public function __construct(
        private readonly DocumentInventory $inventaire,
        private readonly DocumentVault $vault,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sauvegarde = config('preuve.backup.disk');

        if (! is_string($sauvegarde) || $sauvegarde === '') {
            $this->components->error('Aucun disque de sauvegarde configuré (preuve.backup.disk).');

            return self::FAILURE;
        }

        $cible = config('preuve.documents.disk');
        $cible = is_string($cible) && $cible !== '' ? $cible : 's3';

        if ($sauvegarde === $cible) {
            $this->components->error(
                "Le disque de sauvegarde est celui des documents ({$cible}) : il n'y a rien à remonter."
            );

            return self::FAILURE;
        }

        $constat = $this->option('dry-run') === true;
        $plafond = max(1, (int) $this->option('limit'));

        $total = 0;
        $presentes = 0;
        $remontees = 0;
        $restantes = 0;
        $absentes = [];
        $corrompues = [];

        foreach ($this->inventaire->objets() as $objet) {
            $total++;

            if ($this->vault->exists($objet['ref'])) {
                $presentes++;

                continue;
            }

            $archive = $this->inventaire->backupPath($objet['sha']);

            if (! Storage::disk($sauvegarde)->exists($archive)) {
                // Perte réelle : la base réclame une pièce que la sauvegarde
                // n'a pas. Peut aussi signifier que la pièce avait été
                // substituée AVANT sa sauvegarde — elle est alors archivée sous
                // une autre empreinte, et c'est `--verify` qui l'avait signalé.
                $absentes[] = $objet['label'].' → '.substr($objet['sha'], 0, 12).'…';

                continue;
            }

            if ($constat || $remontees >= $plafond) {
                $restantes++;

                continue;
            }

            try {
                $contenu = Crypt::decryptString((string) Storage::disk($sauvegarde)->get($archive));
            } catch (Throwable $e) {
                $corrompues[] = $objet['label'].' (archive illisible : '.$e::class.')';

                continue;
            }

            if (! hash_equals($objet['sha'], hash('sha256', $contenu))) {
                // L'adressage par contenu rend ce cas presque impossible — sauf
                // altération du dépôt de sauvegarde lui-même, précisément ce
                // qu'on veut savoir avant d'écrire.
                $corrompues[] = $objet['label'].' (contenu non conforme à son empreinte)';

                continue;
            }

            // Par le coffre : la référence attendue par la base doit être
            // reproduite à l'identique, chiffrement compris.
            $this->vault->putRaw($objet['ref'], $contenu);
            $remontees++;
        }

        $this->components->twoColumnDetail('Pièces attendues par la base', (string) $total);
        $this->components->twoColumnDetail('Déjà présentes sur le bucket', (string) $presentes);

        if (! $constat) {
            $this->components->twoColumnDetail('Remontées ce passage', (string) $remontees);
        }

        if ($restantes > 0) {
            $this->components->warn(
                $constat
                    ? "{$restantes} pièce(s) seraient remontées."
                    : "{$restantes} pièce(s) non traitées (plafond de {$plafond}). Relancez la commande."
            );
        }

        foreach ($absentes as $absente) {
            $this->components->error('Pièce attendue, absente de la sauvegarde : '.$absente);
        }

        foreach ($corrompues as $corrompue) {
            $this->components->error('Pièce non remontée : '.$corrompue);
        }

        if ($absentes !== [] || $corrompues !== []) {
            $this->components->error(
                'REMONTAGE INCOMPLET. Les biens concernés ont une décision qui s\'appuie sur un document '.
                'que plus personne ne peut produire : traitez-les à la main avant remise en service.'
            );

            return self::FAILURE;
        }

        if ($restantes === 0 && ! $constat) {
            $this->components->info('Toutes les pièces attendues par la base sont en place.');
        }

        return self::SUCCESS;
    }
}
