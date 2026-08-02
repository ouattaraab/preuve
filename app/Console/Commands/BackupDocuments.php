<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sauvegarde du bucket de documents (ST-0904).
 *
 * UNE BASE SANS SES PIÈCES NE RESTAURE PAS LA PLATEFORME. Le dump rend les
 * lignes ; les lignes désignent des clés objet. Sans les objets, une
 * restauration rend un registre dont chaque justificatif, chaque preuve de
 * réclamation et chaque pièce d'identité mène à un fichier introuvable —
 * c'est-à-dire un registre dont plus aucune décision n'est justifiable.
 *
 * ELLE COUVRE LES TROIS FAMILLES, et c'est le point le plus facile à manquer.
 * Le bucket ne porte pas que les justificatifs de biens : il porte aussi les
 * pièces versées aux réclamations — celles sur lesquelles un arbitrage a
 * tranché la propriété de quelqu'un — et les documents d'identité. N'en
 * sauvegarder qu'une famille produirait le pire des résultats : une sauvegarde
 * qui paraît complète.
 *
 * ELLE EST ADRESSÉE PAR CONTENU. Chaque objet est déposé sous l'empreinte de ce
 * qu'il contient : deux pièces identiques ne sont stockées qu'une fois, et
 * savoir si un objet est déjà sauvegardé se ramène à une existence de fichier,
 * sans relire ni renvoyer quoi que ce soit. C'est ce qui rend une sauvegarde
 * quotidienne tenable sur un mutualisé.
 *
 * ELLE NE VÉRIFIE PAS L'INTÉGRITÉ, ET C'EST DÉLIBÉRÉ. Justement parce qu'elle
 * saute ce qui est déjà sauvegardé sans le relire, elle ne peut rien constater
 * sur une pièce substituée après coup — la seule à laquelle elle ne touche
 * plus. Confondre les deux donnerait soit une sauvegarde quotidienne qui relit
 * tout le bucket, soit un contrôle d'intégrité qui cesse silencieusement de
 * fonctionner dès le lendemain. Le contrôle est donc une opération distincte,
 * `--verify`, plus coûteuse et plus espacée.
 *
 * ELLE NE PURGE JAMAIS. Contrairement aux dumps, dont seul le dernier compte,
 * chaque objet sauvegardé est l'unique copie d'une pièce distincte : élaguer
 * par ancienneté effacerait la sauvegarde d'une carte grise de deux ans, qui
 * justifie toujours une propriété aujourd'hui.
 */
final class BackupDocuments extends Command
{
    protected $signature = 'preuve:backup-documents
                            {--limit=500 : Objets traités au plus par passage}
                            {--verify : Relit chaque pièce et confronte son empreinte à celle du dépôt}';

    protected $description = 'Sauvegarde chiffrée des pièces du bucket, adressées par contenu';

    /** @var array<string, array{table: string, colonnes: array<string, string>}> */
    private const FAMILLES = [
        'justificatif' => [
            'table' => 'asset_documents',
            'colonnes' => ['file_ref' => 'file_sha256'],
        ],
        'réclamation' => [
            'table' => 'claim_evidences',
            'colonnes' => ['file_ref' => 'file_sha256'],
        ],
        'identité' => [
            'table' => 'kyc_submissions',
            'colonnes' => [
                'id_front_ref' => 'id_front_sha256',
                'id_back_ref' => 'id_back_sha256',
                'selfie_ref' => 'selfie_sha256',
            ],
        ],
    ];

    public function handle(): int
    {
        $sauvegarde = config('preuve.backup.disk');

        if (! is_string($sauvegarde) || $sauvegarde === '') {
            $this->components->error(
                'Aucun disque de sauvegarde configuré (preuve.backup.disk) : une sauvegarde qui reste sur '.
                'la machine qu\'elle sauvegarde ne protège de rien.'
            );

            return self::FAILURE;
        }

        $source = config('preuve.documents.disk');
        $source = is_string($source) && $source !== '' ? $source : 's3';

        if ($sauvegarde === $source) {
            $this->components->error(
                "Le disque de sauvegarde est celui des documents ({$source}) : une copie qui vit à côté de ".
                "l'original ne protège ni d'une panne, ni d'un effacement."
            );

            return self::FAILURE;
        }

        return $this->option('verify') === true
            ? $this->verifier($source, $sauvegarde)
            : $this->sauvegarder($source, $sauvegarde);
    }

    /**
     * Copie ce qui manque. Ne relit jamais ce qui est déjà là — c'est ce qui la
     * rend tenable tous les jours, et ce qui l'empêche de constater quoi que ce
     * soit sur les pièces qu'elle ne touche plus.
     */
    private function sauvegarder(string $source, string $sauvegarde): int
    {
        $plafond = max(1, (int) $this->option('limit'));

        $total = 0;
        $deja = 0;
        $copies = 0;
        $restants = 0;
        $manquants = [];
        $alteres = [];

        foreach ($this->objets() as $objet) {
            $total++;

            if (Storage::disk($sauvegarde)->exists($this->backupPath($objet['sha']))) {
                $deja++;

                continue;
            }

            if ($copies >= $plafond) {
                $restants++;

                continue;
            }

            $contenu = $this->lire($source, $objet, $manquants);

            if ($contenu === null) {
                continue;
            }

            $reelle = hash('sha256', $contenu);

            if (! hash_equals($objet['sha'], $reelle)) {
                // Relevé au passage, puisqu'on lisait de toute façon. Ce n'est
                // pas le contrôle d'intégrité : celui-ci relit TOUT (--verify).
                $alteres[] = $this->ecart($objet, $reelle);

                if (Storage::disk($sauvegarde)->exists($this->backupPath($reelle))) {
                    $deja++;

                    continue;
                }
            }

            // Sauvegardée sous son empreinte RÉELLE, altérée ou non : refuser
            // laisserait pour seule copie celle, peut-être substituée, du
            // bucket.
            Storage::disk($sauvegarde)->put($this->backupPath($reelle), Crypt::encryptString($contenu));
            $copies++;
        }

        $this->components->twoColumnDetail('Pièces référencées', (string) $total);
        $this->components->twoColumnDetail('Déjà sauvegardées', (string) $deja);
        $this->components->twoColumnDetail('Copiées ce passage', (string) $copies);

        if ($restants > 0) {
            // Dit plutôt que tu : un plafond silencieux se lit comme une
            // couverture complète.
            $this->components->warn(
                "{$restants} pièce(s) non traitées (plafond de {$plafond}). Relancez la commande."
            );
        }

        $this->rapporter($manquants, $alteres);

        if ($manquants !== [] || $alteres !== []) {
            return self::FAILURE;
        }

        if ($restants === 0) {
            $this->components->info('Toutes les pièces référencées sont sauvegardées et chiffrées.');
        }

        $this->components->warn(
            'La sauvegarde des pièces ne vaut qu\'AVEC celle de la base : les objets sont adressés par '.
            'contenu, et seule la base dit quelle empreinte correspond à quel bien.'
        );

        return self::SUCCESS;
    }

    /**
     * Relit CHAQUE pièce et confronte son empreinte à celle figée au dépôt.
     *
     * C'est le seul chemin qui détecte une pièce substituée dans le stockage
     * APRÈS avoir été sauvegardée — exactement ce que `file_sha256` existe pour
     * attraper, et exactement ce qu'une sauvegarde incrémentale ne peut pas
     * voir. Coûteux : à passer plus rarement, pas à chaque nuit.
     */
    private function verifier(string $source, string $sauvegarde): int
    {
        $total = 0;
        $intactes = 0;
        $nonSauvegardees = 0;
        $manquants = [];
        $alteres = [];

        foreach ($this->objets() as $objet) {
            $total++;

            $contenu = $this->lire($source, $objet, $manquants);

            if ($contenu === null) {
                continue;
            }

            $reelle = hash('sha256', $contenu);

            if (hash_equals($objet['sha'], $reelle)) {
                $intactes++;
            } else {
                $alteres[] = $this->ecart($objet, $reelle);
            }

            if (! Storage::disk($sauvegarde)->exists($this->backupPath($reelle))) {
                $nonSauvegardees++;
            }
        }

        $this->components->twoColumnDetail('Pièces référencées', (string) $total);
        $this->components->twoColumnDetail('Empreintes conformes au dépôt', (string) $intactes);

        if ($nonSauvegardees > 0) {
            $this->components->warn("{$nonSauvegardees} pièce(s) restent à sauvegarder.");
        }

        $this->rapporter($manquants, $alteres);

        return $manquants === [] && $alteres === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{label: string, ref: string, sha: string}  $objet
     * @param  list<string>  $manquants
     */
    private function lire(string $source, array $objet, array &$manquants): ?string
    {
        try {
            if (! Storage::disk($source)->exists($objet['ref'])) {
                // Une pièce référencée mais absente du bucket veut dire qu'une
                // décision s'appuie sur un document que plus personne ne peut
                // produire.
                $manquants[] = $objet['label'].' → '.$objet['ref'];

                return null;
            }

            return (string) Storage::disk($source)->get($objet['ref']);
        } catch (Throwable $e) {
            $manquants[] = $objet['label'].' → '.$objet['ref'].' ('.$e::class.')';

            return null;
        }
    }

    /** @param array{label: string, ref: string, sha: string} $objet */
    private function ecart(array $objet, string $reelle): string
    {
        return $objet['label'].' → empreinte au dépôt '.substr($objet['sha'], 0, 12).
            '…, empreinte actuelle '.substr($reelle, 0, 12).'…';
    }

    /**
     * @param  list<string>  $manquants
     * @param  list<string>  $alteres
     */
    private function rapporter(array $manquants, array $alteres): void
    {
        foreach ($manquants as $manquant) {
            $this->components->error('Pièce référencée introuvable dans le bucket : '.$manquant);
        }

        foreach ($alteres as $altere) {
            $this->components->error('Pièce modifiée depuis son dépôt : '.$altere);
        }
    }

    /**
     * Énumère les pièces DEPUIS LA BASE, jamais depuis le bucket.
     *
     * La base est l'index de ce qui est une pièce : un objet présent sur le
     * bucket sans ligne qui le désigne n'a ni propriétaire déclaré, ni chemin
     * de revue, et le sauvegarder perpétuerait une donnée qui devrait être
     * purgée. Lister un bucket de production coûterait par ailleurs bien plus
     * qu'une lecture indexée.
     *
     * @return Generator<int, array{label: string, ref: string, sha: string}>
     */
    private function objets(): Generator
    {
        foreach (self::FAMILLES as $famille => $definition) {
            $colonnes = array_merge(
                ['id'],
                array_keys($definition['colonnes']),
                array_values($definition['colonnes']),
            );

            $lignes = DB::table($definition['table'])->orderBy('id')->select($colonnes)->cursor();

            foreach ($lignes as $ligne) {
                $ligne = (array) $ligne;

                foreach ($definition['colonnes'] as $colonneRef => $colonneSha) {
                    $ref = $ligne[$colonneRef] ?? null;
                    $sha = $ligne[$colonneSha] ?? null;

                    // Une pièce de réclamation peut être une déclaration sans
                    // fichier : ce n'est pas une anomalie.
                    if (! is_string($ref) || $ref === '' || ! is_string($sha) || $sha === '') {
                        continue;
                    }

                    yield [
                        'label' => $famille.' #'.(is_scalar($ligne['id'] ?? null) ? (string) $ligne['id'] : '?').
                            ' ('.$colonneRef.')',
                        'ref' => $ref,
                        'sha' => $sha,
                    ];
                }
            }
        }
    }

    /**
     * Adressage par contenu, réparti sur deux niveaux : un répertoire unique
     * de plusieurs dizaines de milliers d'objets devient impraticable à lister
     * comme à parcourir, y compris pour la restauration.
     */
    private function backupPath(string $sha): string
    {
        return 'documents/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha.'.enc';
    }
}
