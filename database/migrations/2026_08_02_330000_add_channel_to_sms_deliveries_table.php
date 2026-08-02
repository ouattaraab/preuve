<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canal d'acheminement des alertes critiques (décision D7).
 *
 * LE STATUT ET LE CANAL SONT ORTHOGONAUX, et les confondre était une erreur :
 * une première version ajoutait `mail_sent` et `mail_failed` au statut, ce qui
 * aurait obligé à multiplier les valeurs à chaque canal ajouté — et rendu
 * impossible une question aussi simple que « combien d'alertes ont échoué,
 * tous canaux confondus ».
 *
 * `no_channel` rejoint le statut, en revanche, parce que c'est bien une issue
 * d'acheminement : le destinataire n'a ni numéro joignable ni adresse. Ne pas
 * la distinguer d'un échec technique masquerait un problème de données derrière
 * un problème de passerelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_deliveries', function (): void {
            DB::statement(
                "ALTER TABLE sms_deliveries
                 ADD COLUMN channel VARCHAR(10) NOT NULL DEFAULT 'sms' AFTER notification_type,
                 MODIFY COLUMN status ENUM('sent', 'failed', 'no_channel') NOT NULL"
            );
        });
    }

    public function down(): void
    {
        // Les lignes acheminées par courriel deviendraient indiscernables des
        // SMS : elles sont ramenées au statut d'échec plutôt que de mentir sur
        // leur canal.
        DB::statement("UPDATE sms_deliveries SET status = 'failed' WHERE status = 'no_channel'");

        DB::statement(
            "ALTER TABLE sms_deliveries
             DROP COLUMN channel,
             MODIFY COLUMN status ENUM('sent', 'failed') NOT NULL"
        );
    }
};
