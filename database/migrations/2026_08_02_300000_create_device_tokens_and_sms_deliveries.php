<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons d'appareil pour le push (ST-1003) et journal de coût SMS (ST-1004).
 *
 * `sms_deliveries` NE CONTIENT AUCUN DESTINATAIRE. Le critère d'acceptation
 * demande un coût tracé, pas un carnet d'envois : savoir combien de SMS
 * critiques partent et pour quels motifs suffit à piloter la dépense, alors
 * qu'un journal nominatif ajouterait une donnée personnelle de plus à protéger,
 * à purger, et à justifier devant l'ARTCI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('token', 255);
            $table->enum('platform', ['android', 'ios'])->default('android');
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            // Un jeton appartient à un appareil, pas à un compte : le
            // réenregistrer après un changement de propriétaire du téléphone
            // doit remplacer l'ancien rattachement, jamais en créer un second.
            $table->unique('token', 'uq_device_token');
            $table->index('user_id', 'idx_device_user');
        });

        Schema::create('sms_deliveries', function (Blueprint $table): void {
            $table->id();
            // Le TYPE d'alerte, jamais le destinataire.
            $table->string('notification_type', 40);
            $table->enum('status', ['sent', 'failed']);
            $table->string('failure_class', 120)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['created_at', 'status'], 'idx_sms_cost');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_deliveries');
        Schema::dropIfExists('device_tokens');
    }
};
