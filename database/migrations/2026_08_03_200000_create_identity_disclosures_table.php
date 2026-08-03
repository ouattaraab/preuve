<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registre des levées d'anonymat (Loi 2013-450, réquisition judiciaire).
 *
 * LA LEVÉE EST UN ÉVÉNEMENT, PAS UN DROIT OUVERT. Chaque ligne consigne une
 * divulgation qui a eu lieu, avec son fondement légal. Il n'existe aucun état
 * « déverrouillé » : sans nouvelle réquisition, l'identité redevient
 * inaccessible à la seconde suivante. Un droit qui dure serait un droit qu'on
 * oublie d'éteindre.
 *
 * ELLE EST APPEND-ONLY, comme la chaîne d'audit et pour la même raison : un
 * registre de divulgations qu'on peut réécrire ne prouve rien, et c'est
 * précisément le document qu'une autorité de protection des données
 * demanderait à voir.
 *
 * CE QUI A ÉTÉ DIVULGUÉ N'EST PAS RECOPIÉ ICI. La ligne dit QUI a demandé, SUR
 * QUI, EN VERTU DE QUOI et QUAND — jamais le numéro ni l'adresse. Recopier les
 * données divulguées ferait de ce registre une seconde base de données
 * personnelles, permanente celle-là.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_disclosures', function (Blueprint $table): void {
            $table->id();
            // Sujet de la divulgation, et agent qui l'a demandée.
            $table->foreignId('subject_user_id')->constrained('users');
            $table->foreignId('requested_by')->constrained('users');
            // Fondement légal, structuré et obligatoire : un champ libre unique
            // se remplirait de « enquête » et ne prouverait rien.
            $table->string('authority', 160);
            $table->string('reference', 120);
            $table->date('issued_on');
            $table->string('purpose', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_user_id', 'created_at'], 'idx_disclosures_subject');
            $table->index('created_at', 'idx_disclosures_date');
        });

        // Append-only, par déclencheurs : la protection ne dépend pas de la
        // discipline du code qui viendra plus tard.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER identity_disclosures_interdit_update
            BEFORE UPDATE ON identity_disclosures
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = "Le registre des levées d'anonymat est append-only : mise à jour interdite.";
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER identity_disclosures_interdit_delete
            BEFORE DELETE ON identity_disclosures
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = "Le registre des levées d'anonymat est append-only : suppression interdite.";
            END
        SQL);
    }

    /**
     * `down()` retire les déclencheurs mais NE SUPPRIME PAS la table : un
     * registre de divulgations effacé par une migration à rebours serait
     * exactement l'accident que ce registre existe pour rendre impossible.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS identity_disclosures_interdit_update');
        DB::unprepared('DROP TRIGGER IF EXISTS identity_disclosures_interdit_delete');
    }
};
