<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durée de traitement d'une consultation, en millisecondes (ST-0903).
 *
 * CT-01 promet un résultat en moins d'une seconde au 95e centile. Sans mesure,
 * cette promesse n'est qu'une intention : on ne saurait ni la vérifier, ni
 * détecter le jour où elle cesse d'être tenue — et c'est le critère le plus
 * visible du produit, celui qu'un acheteur éprouve debout au marché.
 *
 * La mesure est côté serveur : elle ne couvre pas la latence réseau 3G, mais
 * c'est la seule part que la plateforme maîtrise, et sa dérive est le signal
 * qui compte. Elle vit dans `lookups`, déjà purgée à 12 mois, plutôt que dans
 * une table de métriques à faire vivre en parallèle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lookups', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_ms')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('lookups', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};
