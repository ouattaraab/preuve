<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collaborateurs d'une flotte (ST-0705).
 *
 * Le besoin réel : un loueur qui ne peut pas déléguer partage son téléphone
 * avec ses agents de comptoir. Toutes les actions apparaissent alors sous son
 * nom, et la traçabilité par acteur — que la chaîne d'audit garantit
 * techniquement — ne vaut plus rien en pratique. Donner un compte à chacun est
 * donc autant une mesure d'exploitation qu'une mesure de preuve.
 *
 * `invited_phone` permet d'inviter quelqu'un qui n'a pas encore de compte : un
 * agent de comptoir embauché lundi ne s'inscrira pas avant d'en avoir besoin.
 * Le rattachement se fait à sa première connexion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            // NULL tant que l'invité ne s'est pas connecté.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('invited_phone', 20);
            $table->enum('role', ['admin', 'operator'])->default('operator');
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'invited_phone'], 'uq_members_company_phone');
            $table->index(['user_id', 'is_active'], 'idx_members_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_members');
    }
};
