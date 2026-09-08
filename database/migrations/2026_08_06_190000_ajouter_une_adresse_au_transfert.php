<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un transfert peut désormais porter une ADRESSE en plus du numéro.
 *
 * POURQUOI. Le transfert s'adresse à un numéro, et le destinataire est prévenu
 * par SMS — un canal qui n'existe pas. Concrètement, un vendeur pouvait ouvrir
 * une cession que l'acheteur n'apprenait JAMAIS : elle expirait au bout de sept
 * jours sans que personne ne comprenne pourquoi.
 *
 * L'ADRESSE NE REMPLACE PAS LE NUMÉRO, elle s'y ajoute. Le numéro reste ce qui
 * DÉSIGNE le destinataire — c'est sur lui que porte le contrôle au moment
 * d'accepter, et le changer ferait basculer une règle de propriété. L'adresse
 * ne sert qu'à le PRÉVENIR.
 *
 * Elle est facultative des deux côtés : un vendeur qui ne connaît que le numéro
 * de l'acheteur doit pouvoir ouvrir la cession quand même, et un acheteur ouvert
 * par adreste n'a pas forcément de numéro.
 *
 * `down()` ne supprime pas la colonne : des transferts en cours la porteraient,
 * et les priver de leur seul canal d'avertissement les rendrait muets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            // Le numéro devient facultatif : un destinataire peut n'être connu
            // que par son adresse, depuis que les comptes s'ouvrent ainsi.
            $table->string('to_phone', 20)->nullable()->change();
            $table->string('to_email', 150)->nullable()->after('to_phone');
            $table->index('to_email', 'idx_transfers_to_email');
        });
    }

    public function down(): void
    {
        // Volontairement vide. Voir l'en-tête.
    }
};
