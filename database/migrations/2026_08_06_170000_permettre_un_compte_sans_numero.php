<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte peut désormais s'ouvrir avec une adresse électronique SEULE.
 *
 * POURQUOI MAINTENANT. Aucune passerelle SMS n'est branchée : le code de
 * connexion part par courriel, mais il ne partait qu'à l'adresse d'un compte
 * DÉJÀ identifié par un numéro. Autrement dit, la seule voie d'entrée exigeait
 * un canal qui n'existe pas. Le produit était fermé à quiconque n'avait pas
 * déjà un compte.
 *
 * `phone` DEVIENT FACULTATIF, ET RESTE UNIQUE. En MySQL, un index unique
 * tolère plusieurs NULL : deux comptes sans numéro coexistent donc sans se
 * heurter, tandis que deux comptes portant le même numéro restent impossibles.
 * C'est exactement la garantie qu'on veut, et elle ne coûte rien.
 *
 * CE QUE CELA NE CHANGE PAS. Un transfert de propriété s'adresse à un NUMÉRO
 * (`transfers.to_phone`), et une invitation de flotte aussi. Un compte ouvert
 * par courriel peut donc consulter, enregistrer, déclarer un vol et réclamer —
 * mais il devra renseigner un numéro pour RECEVOIR un bien. C'est une limite
 * assumée, pas un oubli : l'adresser autrement demanderait de repenser le
 * transfert, et le priver de tout le reste en attendant serait absurde.
 *
 * `down()` NE REMET PAS LA CONTRAINTE : des comptes sans numéro existeront, et
 * exiger à nouveau un numéro les rendrait non conformes — la migration
 * échouerait, ou pire, elle passerait en leur inventant une valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volontairement vide. Voir l'en-tête.
    }
};
