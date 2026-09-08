<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table): void {
            $table->id();
            // Téléphone E.164 ou e-mail selon le canal
            $table->string('destination', 150);
            $table->enum('channel', ['sms', 'email']);
            $table->enum('purpose', [
                'login', 'register', 'sensitive_action', 'transfer', 'guest_payment',
            ]);
            // Le code n'est jamais stocké en clair
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            // Verrouillage progressif après épuisement des tentatives
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->index(['destination', 'purpose', 'consumed_at'], 'idx_otp_lookup');
            $table->index('expires_at', 'idx_otp_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
