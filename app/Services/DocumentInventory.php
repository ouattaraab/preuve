<?php

declare(strict_types=1);

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Inventaire des pièces du bucket (ST-0904).
 *
 * DÉFINI ICI ET NULLE PART AILLEURS. La sauvegarde et le remontage doivent
 * parcourir exactement le même ensemble : deux énumérations distinctes
 * finiraient par diverger — une famille ajoutée d'un côté, oubliée de l'autre —
 * et la divergence ne se verrait que le jour d'une restauration, quand il
 * serait trop tard pour la corriger.
 *
 * LES TROIS FAMILLES. Le bucket ne porte pas que les justificatifs de biens :
 * il porte aussi les pièces versées aux réclamations — celles sur lesquelles un
 * arbitrage a tranché la propriété de quelqu'un — et les documents d'identité.
 */
final class DocumentInventory
{
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

    /**
     * Préfixes sous lesquels les services déposent les pièces.
     *
     * Utilisés par la réconciliation, qui balaie le bucket : sans eux, elle
     * rapporterait comme orphelin tout ce qui partage le disque sans être une
     * pièce — ancrages d'audit, marqueurs de dépôt, résidus d'exploitation. Un
     * rapport noyé sous de faux positifs n'est plus lu, et c'est celui-là qui
     * doit l'être après un sinistre.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return ['assets', 'claims', 'kyc'];
    }

    /**
     * Énumère les pièces DEPUIS LA BASE, jamais depuis le bucket.
     *
     * La base est l'index de ce qui est une pièce : un objet présent sur le
     * bucket sans ligne qui le désigne n'a ni propriétaire déclaré, ni chemin
     * de revue, et le traiter perpétuerait une donnée qui devrait être purgée.
     * Lister un bucket de production coûterait par ailleurs bien plus qu'une
     * lecture indexée.
     *
     * @return Generator<int, array{label: string, ref: string, sha: string}>
     */
    public function objets(): Generator
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
     * Emplacement de sauvegarde d'une pièce, adressé par son contenu.
     *
     * Réparti sur deux niveaux : un répertoire unique de plusieurs dizaines de
     * milliers d'objets devient impraticable à lister comme à parcourir, y
     * compris au remontage.
     */
    public function backupPath(string $sha): string
    {
        return 'documents/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha.'.enc';
    }
}
