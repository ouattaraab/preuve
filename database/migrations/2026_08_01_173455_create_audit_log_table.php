<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->enum('actor_type', ['user', 'agent', 'system']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->json('payload');
            $table->char('record_hash', 64);
            $table->char('prev_hash', 64);
            $table->char('chain_hash', 64);
            // DATETIME (pas TIMESTAMP) : un TIMESTAMP restitue sa
            // représentation textuelle selon le fuseau de la session
            // (`time_zone`), ce qui rendrait l'empreinte de la chaîne (§4.5
            // de la spec, qui hache cette représentation textuelle) instable
            // selon le client ou le fuseau système de l'hébergeur. Un
            // DATETIME ne subit aucune conversion de fuseau.
            $table->dateTime('created_at')->useCurrent();

            $table->unique('chain_hash', 'uq_audit_chain');
            $table->index(['entity_type', 'entity_id', 'created_at'], 'idx_audit_entity');
            $table->index(['action', 'created_at'], 'idx_audit_action');
        });
    }

    /**
     * Table d'audit : la suppression n'est autorisée qu'au tout premier
     * déroulement, sur une base vide. Aucune migration ultérieure ne doit
     * supprimer ni modifier destructivement cette table.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
