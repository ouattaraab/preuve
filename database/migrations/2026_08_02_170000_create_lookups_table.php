<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des consultations publiques (ST-0304).
 *
 * Sert trois usages, et aucun autre : limiter le rythme des consultations
 * anonymes (§8, anti-profilage), détecter les pics de consultation d'un même
 * bien — signal de revente imminente — et alimenter la notification agrégée du
 * détenteur.
 *
 * L'adresse IP n'y figure JAMAIS en clair (Loi 2013-450) : seule une empreinte
 * salée par jour est conservée, et les lignes sont purgées au-delà de 12 mois.
 * Aucune clé étrangère vers `assets` : un bien effacé ne doit pas emporter le
 * journal, et le journal ne doit pas empêcher son effacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier_normalized', 64);
            // NULL quand l'identifiant est inconnu : c'est précisément le cas
            // qu'il faut pouvoir compter (tentatives de balayage).
            $table->unsignedBigInteger('found_asset_id')->nullable();
            $table->char('ip_hash', 64);
            // NULL si consultation anonyme — le cas nominal, la consultation
            // n'exigeant jamais de compte (règle métier absolue n° 1).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('source', ['app', 'web'])->default('app');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['identifier_normalized', 'created_at'], 'idx_lookups_identifier');
            $table->index(['ip_hash', 'created_at'], 'idx_lookups_rate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
