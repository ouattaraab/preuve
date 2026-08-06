<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ouvre `payments.purpose` aux deux motifs liés au vol (ST-0805).
 *
 * L'ÉNUMÉRATION PHP ET CELLE DE LA BASE DOIVENT RESTER EN PHASE, et rien ne
 * l'impose automatiquement. Ajouter un cas au premier sans toucher au second
 * produit une erreur `Data truncated for column 'purpose'` au premier
 * paiement — une 500 opaque, au moment précis où quelqu'un essaie de payer.
 *
 * `down()` ne rétrécit pas l'énumération : des paiements portant ces motifs
 * existeraient, et les rendre non conformes tronquerait leur colonne en
 * silence — c'est-à-dire perdrait la trace de ce pour quoi de l'argent a été
 * encaissé.
 */
return new class extends Migration
{
    private const MOTIFS = [
        'detailed_report',
        'claim_fee',
        'asset_slot',
        'fleet_subscription',
        'theft_declaration',
        'theft_listing',
    ];

    public function up(): void
    {
        $valeurs = implode(',', array_map(
            static fn (string $motif): string => "'".$motif."'",
            self::MOTIFS,
        ));

        DB::statement("ALTER TABLE payments MODIFY COLUMN purpose ENUM({$valeurs}) NOT NULL");
    }

    public function down(): void
    {
        // Volontairement vide. Voir l'en-tête.
    }
};
