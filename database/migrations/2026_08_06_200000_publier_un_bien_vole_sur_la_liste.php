<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue le bien DÉCLARÉ volé du bien PUBLIÉ sur la liste (ST-0805).
 *
 * LA DISTINCTION EST LE CŒUR DU DISPOSITIF, et elle décide de ce que le produit
 * monnaie.
 *
 * Déclarer un vol rend le bien invendable pour quiconque VÉRIFIE son numéro :
 * c'est la protection, elle est immédiate, et elle reste gratuite. Celui qui
 * déclare vient de se faire dépouiller, et la valeur du registre vient de sa
 * couverture — un vol non déclaré est un bien volé que personne ne peut
 * reconnaître.
 *
 * Être PUBLIÉ sur la liste que tout le monde parcourt est autre chose : de la
 * visibilité, offerte à qui la demande. C'est un service en plus, et c'est lui
 * qu'on facture — ce qui permet précisément à la protection de rester gratuite.
 *
 * LA PUBLICATION EST UN CONSENTEMENT. Elle expose l'identifiant réel du bien —
 * une plaque, un châssis — à qui parcourt la liste, alors que le registre
 * refuse par ailleurs qu'on l'énumère. Ce n'est pas une contradiction : ici le
 * détenteur DEMANDE cette exposition, parce qu'elle sert son intérêt. Elle ne
 * doit donc jamais être posée sans son geste explicite.
 *
 * `down()` ne supprime pas la colonne : elle porte un consentement daté, et
 * l'effacer republierait ou dépublierait des biens sans que personne l'ait
 * demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dateTime('stolen_listed_at')->nullable()->after('stolen_consolidated');

            // L'index porte les DEUX colonnes qui filtrent la liste publique :
            // elle n'affiche que ce qui est à la fois volé et publié, et cette
            // page sera parcourue par tout le monde.
            $table->index(['life_status', 'stolen_listed_at'], 'idx_assets_stolen_listed');
        });
    }

    public function down(): void
    {
        // Volontairement vide. Voir l'en-tête.
    }
};
