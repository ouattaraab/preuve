<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extraite de la migration users d'origine de Laravel : la tâche 4 remplace
// le contenu de 0001_01_01_000000_create_users_table.php par le schéma métier
// des utilisateurs, mais la configuration de session utilise le driver
// `database` (SESSION_DRIVER=database) : cette table reste nécessaire.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
