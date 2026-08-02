<?php

declare(strict_types=1);

namespace App\Services\Scan;

use Illuminate\Http\UploadedFile;

/**
 * Lecture d'une carte grise ou d'une facture pour pré-remplir l'identifiant
 * (ST-0202).
 *
 * Le contrat existe pour la même raison que celui du lecteur de pièces
 * d'identité : l'extraction échoue souvent en conditions réelles — document
 * plié, photo de nuit, tampon en travers du châssis — et la plateforme doit
 * continuer sans elle. La saisie manuelle n'est pas une roue de secours, c'est
 * le chemin nominal auquel le scan fait gagner du temps quand il y parvient.
 */
interface DocumentReader
{
    public function read(UploadedFile $image): ScanExtraction;
}
