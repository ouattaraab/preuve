<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Veille sur un identifiant (ST-0403).
 *
 * Porte sur `identifier_normalized` et non sur un `asset_id`, délibérément :
 * une victime de vol dont le bien a été réenregistré par un tiers n'est plus
 * détentrice d'aucune ligne active, et c'est précisément elle qui a le plus
 * besoin d'être alertée. Une veille rattachée au bien disparaîtrait avec lui.
 *
 * Le revers est qu'une veille libre permettrait de surveiller le bien
 * d'autrui — savoir quand il est consulté, donc quand il est mis en vente.
 * Ce serait une violation directe de l'anonymat symétrique (règle métier
 * absolue n° 4). Le droit de veille est donc contrôlé côté service : il faut
 * être, ou avoir été, détenteur d'un enregistrement portant cet identifiant.
 *
 * `last_triggered_at` évite de renotifier en boucle sur un même pic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('identifier_normalized', 64);
            $table->enum('channel', ['push', 'sms', 'both'])->default('push');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_triggered_at')->nullable();
            $table->timestamps();

            // Une seule veille par utilisateur et par identifiant : la
            // réactiver plutôt que d'en empiler plusieurs.
            $table->unique(['user_id', 'identifier_normalized'], 'uq_watch_user_identifier');
            $table->index(['identifier_normalized', 'is_active'], 'idx_watch_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_alerts');
    }
};
