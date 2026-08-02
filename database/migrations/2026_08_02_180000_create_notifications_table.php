<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centre de notifications (EP-10).
 *
 * `body` ne contient JAMAIS l'identité d'un tiers pour les types
 * `asset_lookup` et `asset_report_purchased` : le propriétaire ne voit jamais
 * qui consulte son bien, même quand ce tiers a payé (règle métier absolue
 * n° 4, versant symétrique).
 *
 * Les clés étrangères vers `claims` sont volontairement absentes : la table
 * n'existe pas encore. Elles seront ajoutées avec elle plutôt que de bloquer
 * ici le centre de notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('type', [
                'asset_lookup', 'asset_report_purchased', 'duplicate_attempt', 'lookup_spike',
                'claim_opened', 'claim_decided', 'transfer_invitation', 'transfer_completed',
                'status_change', 'kyc_result', 'system',
            ]);
            $table->foreignId('asset_id')->nullable()->constrained('assets');
            $table->unsignedBigInteger('claim_id')->nullable();
            $table->string('title', 150);
            $table->string('body', 500);
            // Données d'affichage : compteur de consultations agrégées, période.
            // Jamais d'identité ni d'adresse IP.
            $table->json('payload')->nullable();
            $table->enum('channel', ['inapp', 'push', 'sms'])->default('inapp');
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['user_id', 'read_at', 'created_at'], 'idx_notif_user_unread');
            $table->index('asset_id', 'idx_notif_asset');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Extension au schéma de référence, qui ne prévoyait rien pour
            // ST-0107. NULL = préférences par défaut : tout est reçu, ce qui
            // est le comportement attendu d'un compte neuf — un propriétaire
            // ne doit pas avoir à activer les alertes sur ses propres biens.
            $table->json('notification_preferences')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });

        Schema::dropIfExists('notifications');
    }
};
