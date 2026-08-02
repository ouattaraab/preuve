<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DocumentInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Réconciliation du bucket et de la base après un sinistre partiel (ST-0904).
 *
 * LE SINISTRE PARTIEL EST LE CAS ORDINAIRE. Une perte totale se voit et se
 * traite ; ce qui arrive vraiment, c'est une base remontée à J-1 pendant que le
 * bucket est resté à J, ou l'inverse. Les deux moitiés de la plateforme
 * divergent alors sans que rien ne le signale, et chacune paraît saine prise
 * isolément.
 *
 * ELLE REGARDE DANS LES DEUX SENS, et c'est tout son objet.
 *
 * 1. Ce que la base réclame et que le bucket n'a pas : un bien dont une
 *    décision s'appuie sur un document que plus personne ne peut produire.
 *    Distingué selon qu'il soit récupérable — présent dans la sauvegarde — ou
 *    définitivement perdu, car la conduite à tenir n'est pas la même.
 *
 * 2. Ce que le bucket porte et que plus rien ne référence. C'est le sens que
 *    RIEN d'autre ne voit : la sauvegarde comme le remontage énumèrent depuis
 *    la base, donc une pièce orpheline leur est invisible par construction.
 *    Elle n'est pourtant pas anodine — une carte grise, une facture nominative
 *    ou une pièce d'identité sans propriétaire déclaré, sans chemin de revue et
 *    sans échéance de purge est une donnée personnelle conservée pour rien
 *    (Loi 2013-450, minimisation).
 *
 * ELLE NE SUPPRIME JAMAIS. Un orphelin est le plus souvent une pièce dont la
 * ligne a été perdue : l'effacer détruirait la seule trace restante, au moment
 * précis où elle est la plus précieuse. La réconciliation constate et nomme ;
 * un humain tranche.
 *
 * ELLE COÛTE CHER, contrairement à la sauvegarde quotidienne : elle LISTE le
 * bucket, ce que les autres commandes évitent délibérément. À passer après une
 * restauration, pas toutes les nuits. `--prefix` permet de la mener par
 * morceaux sur un parc volumineux.
 */
final class ReconcileDocuments extends Command
{
    protected $signature = 'preuve:reconcile-documents
                            {--prefix= : Restreint le balayage à un seul préfixe (assets, claims ou kyc)}
                            {--show=20 : Nombre d\'orphelins détaillés dans le rapport}';

    protected $description = 'Confronte le bucket et la base après un sinistre partiel';

    public function __construct(private readonly DocumentInventory $inventaire)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $cible = config('preuve.documents.disk');
        $cible = is_string($cible) && $cible !== '' ? $cible : 's3';

        $sauvegarde = config('preuve.backup.disk');
        $sauvegarde = is_string($sauvegarde) && $sauvegarde !== '' ? $sauvegarde : null;

        $prefixe = $this->option('prefix');
        $prefixe = is_string($prefixe) && $prefixe !== '' ? trim($prefixe, '/') : null;

        $balayes = $this->inventaire->prefixes();

        if ($prefixe !== null) {
            if (! in_array($prefixe, $balayes, true)) {
                $this->components->error(
                    "Préfixe inconnu « {$prefixe} ». Les pièces vivent sous : ".implode(', ', $balayes).'.'
                );

                return self::FAILURE;
            }

            $balayes = [$prefixe];
        }

        // Les clés attendues sont tenues en mémoire : une comparaison par
        // requête coûterait un aller-retour par objet du bucket. À deux cent
        // mille pièces, cet ensemble pèse une dizaine de mégaoctets — tenable,
        // et c'est ce qui rend le balayage praticable en un passage.
        $attendues = [];
        $manquantesRecuperables = [];
        $manquantesPerdues = [];
        $presentes = 0;
        $horsPrefixe = 0;

        foreach ($this->inventaire->objets() as $objet) {
            if ($prefixe !== null && ! str_starts_with($objet['ref'], $prefixe)) {
                $horsPrefixe++;

                continue;
            }

            $attendues[$objet['ref']] = true;

            if (Storage::disk($cible)->exists($objet['ref'])) {
                $presentes++;

                continue;
            }

            // Récupérable ou perdue : la conduite à tenir n'est pas la même, et
            // un rapport qui confondrait les deux ferait chercher longtemps.
            $recuperable = $sauvegarde !== null
                && Storage::disk($sauvegarde)->exists($this->inventaire->backupPath($objet['sha']));

            if ($recuperable) {
                $manquantesRecuperables[] = $objet['label'].' → '.$objet['ref'];
            } else {
                $manquantesPerdues[] = $objet['label'].' → '.$objet['ref'];
            }
        }

        // Le balayage se limite aux préfixes où les services déposent : hors
        // d'eux, un fichier n'est pas une pièce, et le rapporter noierait le
        // rapport sous des faux positifs. En production le bucket est dédié ;
        // en développement il partage le disque avec les ancrages d'audit.
        $orphelins = [];
        $objetsBucket = 0;

        foreach ($balayes as $racine) {
            foreach (Storage::disk($cible)->allFiles($racine) as $cle) {
                if (! is_string($cle)) {
                    continue;
                }

                $objetsBucket++;

                if (! isset($attendues[$cle])) {
                    $orphelins[] = $cle;
                }
            }
        }

        $this->components->twoColumnDetail('Pièces attendues par la base', (string) count($attendues));
        $this->components->twoColumnDetail('Présentes sur le bucket', (string) $presentes);
        $this->components->twoColumnDetail('Objets trouvés sur le bucket', (string) $objetsBucket);
        // Dire ce qui a été balayé : une réconciliation dont on ignore la
        // portée ne conclut rien.
        $this->components->twoColumnDetail('Préfixes balayés', implode(', ', $balayes));

        if ($horsPrefixe > 0) {
            // Un balayage partiel qui se tairait se lirait comme un balayage
            // complet.
            $this->components->warn(
                "{$horsPrefixe} pièce(s) hors du préfixe « {$prefixe} » n'ont pas été examinées."
            );
        }

        $this->lister(
            $manquantesRecuperables,
            'Attendue par la base, absente du bucket, PRÉSENTE dans la sauvegarde',
            'Ces pièces se remontent : php artisan preuve:restore-documents',
        );

        $this->lister(
            $manquantesPerdues,
            'Attendue par la base, absente du bucket ET de la sauvegarde',
            'PERTE DÉFINITIVE : ces biens portent une décision dont le justificatif n\'existe plus.',
        );

        if ($orphelins !== []) {
            $this->rapporterOrphelins($orphelins, $cible);
        }

        if ($manquantesRecuperables === [] && $manquantesPerdues === [] && $orphelins === []) {
            $this->components->info('Le bucket et la base concordent.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $lignes
     */
    private function lister(array $lignes, string $titre, string $conduite): void
    {
        if ($lignes === []) {
            return;
        }

        $this->components->error(count($lignes).' — '.$titre);

        foreach ($lignes as $ligne) {
            $this->line('   '.$ligne);
        }

        $this->components->warn($conduite);
    }

    /**
     * Les orphelins sont détaillés avec leur taille et leur date : c'est ce
     * dont un exploitant a besoin pour décider s'il s'agit d'une pièce dont la
     * ligne a été perdue, ou d'un résidu.
     *
     * @param  list<string>  $orphelins
     */
    private function rapporterOrphelins(array $orphelins, string $cible): void
    {
        $this->components->error(
            count($orphelins).' — Objet sur le bucket que plus rien ne référence'
        );

        $detailles = max(1, (int) $this->option('show'));

        foreach (array_slice($orphelins, 0, $detailles) as $cle) {
            $taille = Storage::disk($cible)->size($cle);
            $modifie = Storage::disk($cible)->lastModified($cle);

            $this->line(sprintf(
                '   %s (%s Ko, %s)',
                $cle,
                number_format($taille / 1024, 0, ',', ' '),
                date('d/m/Y H:i', $modifie),
            ));
        }

        if (count($orphelins) > $detailles) {
            $this->line('   … et '.(count($orphelins) - $detailles).' autre(s).');
        }

        $this->components->warn(
            'RIEN N\'EST SUPPRIMÉ. Un orphelin est le plus souvent une pièce dont la ligne a été perdue : '.
            'l\'effacer détruirait la seule trace restante. Mais une pièce nominative sans propriétaire '.
            'déclaré ni échéance de purge ne doit pas rester indéfiniment (Loi 2013-450) : tranchez.'
        );
    }
}
