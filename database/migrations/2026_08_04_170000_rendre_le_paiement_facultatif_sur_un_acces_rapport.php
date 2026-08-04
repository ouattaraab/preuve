<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un accès au rapport peut désormais exister SANS paiement.
 *
 * POURQUOI : les tarifs sont réglables, et zéro veut dire GRATUIT. Ouvrir une
 * transaction de zéro franc ferait échouer l'opérateur ; créer une ligne de
 * paiement à zéro pour satisfaire une contrainte mettrait un mensonge dans le
 * journal comptable — on y lirait des encaissements qui n'ont jamais eu lieu.
 *
 * ÉCART ASSUMÉ AVEC LE SCHÉMA DE RÉFÉRENCE (`preuve_schema_mysql8.sql`), qui
 * déclare cette colonne obligatoire : il datait d'un temps où le rapport était
 * nécessairement payant. Le jeton d'accès, lui, reste la seule porte — gratuit
 * ou non.
 *
 * PAS DE `down()` DESTRUCTIF : revenir en arrière supprimerait les accès
 * gratuits déjà accordés, c'est-à-dire retirerait à des gens un droit qu'on
 * leur a donné. Le retour se borne à reposer la contrainte, ce qui échouera
 * tant que de tels accès existent — et c'est bien ainsi : cela force à décider
 * de leur sort plutôt qu'à les perdre en silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('report_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_id')->nullable(false)->change();
        });
    }
};
