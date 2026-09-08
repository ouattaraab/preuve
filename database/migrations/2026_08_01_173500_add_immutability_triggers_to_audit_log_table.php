<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Défense en profondeur (§4.6 de la spec) : les garde-fous Eloquent du modèle
 * AuditLog ne couvrent que le chemin des instances (::update()/::delete() sur
 * une instance). Un accès par requête ("AuditLog::query()->update(...)" ou
 * "DB::table('audit_log')->update(...)") les contourne entièrement, car
 * Eloquent ne déclenche d'événements que sur les instances chargées.
 *
 * Ces déclencheurs MariaDB rendent l'interdiction indépendante du code
 * applicatif : toute tentative de UPDATE ou DELETE sur audit_log échoue au
 * niveau du moteur, quel que soit le chemin de code emprunté.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_interdit_update
            BEFORE UPDATE ON audit_log
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = "La chaîne d'audit est append-only : mise à jour interdite.";
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_interdit_delete
            BEFORE DELETE ON audit_log
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = "La chaîne d'audit est append-only : suppression interdite.";
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_interdit_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_interdit_delete');
    }
};
