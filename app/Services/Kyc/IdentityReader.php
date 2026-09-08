<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use Illuminate\Http\UploadedFile;

/**
 * Extraction des données d'une pièce d'identité.
 *
 * Mindee est le fournisseur retenu par la stack, mais l'extraction reste
 * derrière un contrat : elle échoue régulièrement sur des photos prises en
 * conditions réelles — pièce usée, contre-jour, cadrage — et la plateforme doit
 * continuer de fonctionner sans elle. Un agent tranche alors sur la seule
 * image, comme il le ferait de toute façon en dernier ressort.
 *
 * L'extraction ne DÉCIDE jamais : elle propose des champs qu'un agent valide
 * (ST-0103, statut pending → verified/rejected). Une identité vérifiée
 * automatiquement sur la foi d'un OCR ouvrirait la porte à des pièces
 * fabriquées que personne n'aurait regardées.
 */
interface IdentityReader
{
    /**
     * @return IdentityExtraction champs extraits, éventuellement vides
     */
    public function read(UploadedFile $recto, UploadedFile $verso): IdentityExtraction;
}
