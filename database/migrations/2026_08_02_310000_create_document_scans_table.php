<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesure du pré-remplissage par scan (ST-0202).
 *
 * L'identifiant proposé n'est stocké QUE sous forme d'empreinte. Comparer une
 * empreinte suffit pour savoir si l'utilisateur a gardé la proposition ou l'a
 * corrigée — la seule chose que cette table doive dire. En clair, elle
 * deviendrait un registre parallèle de tous les châssis photographiés,
 * alimenté par des gens qui n'ont peut-être jamais enregistré leur bien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('doc_type', 30);
            $table->string('provider', 30);
            // Empreinte HMAC de l'identifiant proposé : jamais la valeur.
            $table->char('proposed_hash', 64)->nullable();
            $table->string('proposed_type', 20)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome', 20)->default('pending');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at'], 'idx_scans_user');
            $table->index('created_at', 'idx_scans_purge');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
