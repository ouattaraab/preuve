<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envois différés avec reprise (ST-0206).
 *
 * Table d'exploitation, hors des 14 tables métier du schéma de référence :
 * elle ne contient aucune donnée du registre, seulement l'état d'un transfert
 * en cours. Une ligne meurt quand le justificatif est constitué, ou à
 * l'expiration.
 *
 * `uuid` vient du CLIENT et porte l'idempotence. Une application mobile qui
 * perd le réseau au moment de la réponse ne sait pas si sa demande a abouti ;
 * si l'identité de la session venait du serveur, chaque réessai ouvrirait un
 * nouvel envoi et le même document partirait cinq fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users');
            // Figé à la création : voir UploadSessionService.
            $table->foreignId('asset_id')->constrained('assets');
            $table->string('doc_type', 30);
            $table->string('filename', 255);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->char('checksum', 64);
            $table->string('status', 20)->default('open');
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('document_id')->nullable()->constrained('asset_documents');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_uploads_user');
            $table->index('expires_at', 'idx_uploads_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
