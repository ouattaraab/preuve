<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dernière alerte de pic émise pour un bien (ST-0405).
 *
 * Portée par le bien plutôt que par la veille : le détenteur courant est
 * prévenu qu'il ait ou non activé une veille — c'est son bien, et le pic peut
 * signaler qu'un tiers le vend à sa place. Sans cette marque, un pic durable
 * produirait une alerte à chaque passage du job, et une alerte répétée finit
 * ignorée — précisément celle qu'il ne faut pas manquer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dateTime('spike_alerted_at')->nullable()->after('stolen_consolidated');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('spike_alerted_at');
        });
    }
};
