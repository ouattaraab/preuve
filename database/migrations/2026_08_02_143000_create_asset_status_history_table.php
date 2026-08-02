<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des transitions de statut (règle métier absolue n° 6 : toute
 * transition écrit une ligne ici). Table en append-only par convention de
 * service — StatusTransitionService n'y écrit que par insertion, jamais par
 * mise à jour — et alimentée dans la même transaction que l'entrée de la
 * chaîne d'audit correspondante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets');
            // NULL uniquement pour la ligne d'origine, à l'enregistrement du
            // bien : il n'existe alors aucun statut antérieur.
            $table->enum('from_status', ['V-ACT', 'V-PRV', 'V-LOC', 'V-VTE', 'V-VOL', 'V-LIT', 'V-FDV'])->nullable();
            $table->enum('to_status', ['V-ACT', 'V-PRV', 'V-LOC', 'V-VTE', 'V-VOL', 'V-LIT', 'V-FDV']);
            // Le niveau de fiabilité évolue indépendamment du statut de vie
            // (deux dimensions distinctes) : ces colonnes restent nulles quand
            // la transition ne le fait pas bouger.
            $table->enum('from_trust', ['F1', 'F2', 'F3'])->nullable();
            $table->enum('to_trust', ['F1', 'F2', 'F3'])->nullable();
            $table->enum('trigger_type', ['system', 'owner', 'claim', 'arbitration', 'transfer', 'backoffice']);
            // NULL pour les transitions déclenchées par un job planifié.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason', 255)->nullable();
            // DATETIME (pas TIMESTAMP), pour la même raison qu'audit_log : ces
            // dates alimentent le rapport détaillé payant et les exports
            // d'arbitrage, et sont journalisées en parallèle dans la chaîne
            // d'audit sous forme DATETIME UTC. Un TIMESTAMP restituerait une
            // représentation textuelle dépendante du fuseau de la session, et
            // les deux horodatages d'une même action divergeraient à la
            // lecture.
            $table->dateTime('created_at')->useCurrent();

            $table->index(['asset_id', 'created_at'], 'idx_ash_asset');
        });
    }

    /**
     * Table d'historique : la suppression n'est autorisée qu'au tout premier
     * déroulement, sur une base vide. Aucune migration ultérieure ne doit
     * supprimer ni modifier destructivement cette table (CLAUDE.md).
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_status_history');
    }
};
