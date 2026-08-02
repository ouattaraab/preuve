<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle de back-office. Par défaut « user » : aucun compte créé par le parcours
 * public d'inscription (ST-0101) ne peut donc accéder au back-office, et
 * l'élévation reste un geste délibéré passant par la chaîne d'audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['user', 'agent', 'admin'])->default('user')->after('account_type');
            $table->index('role', 'idx_users_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_users_role');
            $table->dropColumn('role');
        });
    }
};
