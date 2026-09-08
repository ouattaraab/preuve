<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ancrages externes du hash de tête de la chaîne d'audit (§4.5, systemPatterns §6).
 *
 * Tant que cet ancrage n'existe pas, la chaîne N'EST PAS OPPOSABLE :
 * l'algorithme est public et sans secret, si bien que quiconque dispose du
 * droit INSERT peut reconstruire une chaîne parfaitement cohérente à partir de
 * zéro. Ce qui rend la falsification détectable, c'est qu'une empreinte de la
 * tête a été publiée AILLEURS, à une date, hors de portée de qui contrôle la
 * base.
 *
 * Cette table n'est donc PAS la preuve : elle n'est que le registre local de ce
 * qui a été publié dehors, et sert à retrouver quoi comparer. Elle est
 * néanmoins append-only, par les mêmes déclencheurs qu'audit_log — un attaquant
 * qui pourrait la réécrire masquerait quels ancrages ont réellement eu lieu, et
 * ferait perdre du temps à la vérification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_anchors', function (Blueprint $table): void {
            $table->id();
            // Tête de chaîne au moment de l'ancrage.
            $table->unsignedBigInteger('head_id')->nullable();
            $table->char('head_chain_hash', 64);
            $table->unsignedBigInteger('entry_count');
            // Détail par canal : lequel a réussi, lequel a échoué et pourquoi.
            $table->json('channels');
            // `anchored` seulement si AU MOINS un canal externe a confirmé.
            // Un ancrage resté local ne prouve rien et ne doit pas se faire
            // passer pour une protection.
            $table->enum('status', ['anchored', 'failed']);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['status', 'created_at'], 'idx_anchors_status');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_anchors_interdit_update
            BEFORE UPDATE ON audit_anchors
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = "Le registre d'ancrage est append-only : mise à jour interdite.";
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_anchors_interdit_delete
            BEFORE DELETE ON audit_anchors
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = "Le registre d'ancrage est append-only : suppression interdite.";
            END
        SQL);
    }

    /**
     * Registre d'ancrage : la suppression n'est autorisée qu'au tout premier
     * déroulement, sur une base vide (CLAUDE.md).
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_anchors_interdit_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_anchors_interdit_delete');

        Schema::dropIfExists('audit_anchors');
    }
};
