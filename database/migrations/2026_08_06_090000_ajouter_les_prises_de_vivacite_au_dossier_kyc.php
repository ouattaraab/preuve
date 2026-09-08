<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prises de vue supplémentaires pour apprécier la vivacité (ST-0103).
 *
 * POURQUOI DES IMAGES, ET PAS UN SCORE. La colonne `liveness_score` existe
 * depuis l'origine et vaut toujours nul, faute de fournisseur. La tentation
 * était de la remplir avec ce que le téléphone calcule lui-même — et ç'aurait
 * été PIRE QUE DE LA LAISSER VIDE : une application modifiée enverrait 100, et
 * l'agent, voyant un chiffre, cesserait de regarder. Un score qu'on ne peut pas
 * vérifier n'est pas une mesure, c'est une fausse assurance.
 *
 * Ce qui se vérifie, en revanche, c'est une SUITE D'IMAGES : le visage de face,
 * puis tourné à gauche, puis à droite. Une photo imprimée brandie devant
 * l'objectif ne tourne pas la tête. L'agent tranche, comme il tranchait déjà,
 * mais sur trois prises au lieu d'une.
 *
 * JSON plutôt que des colonnes fixes : le nombre et la nature des prises
 * changeront (clignement, lecture d'un chiffre à l'écran), et chaque variante
 * n'a pas à coûter une migration sur une table qui porte des pièces d'identité.
 *
 * `down()` NE SUPPRIME PAS LA COLONNE : les références qu'elle porte sont le
 * seul lien vers des fichiers chiffrés déjà déposés dans le coffre. Les perdre
 * rendrait ces fichiers irrécupérables et non supprimables — l'inverse de ce
 * que la Loi 2013-450 exige d'un responsable de traitement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table): void {
            $table->json('liveness_frames')->nullable()->after('liveness_score');
        });
    }

    public function down(): void
    {
        // Volontairement vide. Voir l'en-tête.
    }
};
