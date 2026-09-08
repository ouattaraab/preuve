<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imports de flotte différés (ST-0702).
 *
 * Un import de plusieurs milliers de véhicules ne peut pas se traiter dans la
 * requête : chaque enregistrement prend le verrou nommé de la chaîne d'audit,
 * dont le plafond mesuré est d'une quinzaine d'actions simultanées. Cette
 * table porte l'état d'un import en cours, pour que le loueur sache où il en
 * est plutôt que d'attendre devant un écran figé.
 *
 * Elle garde les motifs d'échec, BORNÉS : un fichier dont toutes les lignes
 * sont fautives produirait sinon des mégaoctets de JSON pour une information
 * qui tient en une phrase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('user_id')->constrained('users');
            $table->string('filename', 255);
            // Clé sur le disque de travail, effacée dès l'import terminé : le
            // fichier porte l'inventaire complet d'un parc.
            $table->string('storage_ref', 255)->nullable();
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['company_id', 'status'], 'idx_fleet_imports_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_imports');
    }
};
