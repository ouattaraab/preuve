<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            // E.164, identifiant de connexion — pas de mot de passe au MVP
            $table->string('phone', 20)->unique();
            $table->timestamp('phone_verified_at')->nullable();
            // Optionnel : repli OTP et reçus de paiement (décision D7)
            $table->string('email', 150)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('full_name', 150)->nullable();
            $table->enum('account_type', ['individual', 'company'])->default('individual');
            $table->enum('kyc_status', ['none', 'pending', 'verified', 'rejected'])->default('none');
            // Ancienneté du KYC : signal temporel public non antidatable
            $table->timestamp('kyc_verified_at')->nullable();
            // SHA-256 du n° de CNI — jamais la valeur en clair (Loi 2013-450)
            $table->char('kyc_id_number_hash', 64)->nullable();
            $table->json('kyc_ocr_payload')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->enum('status', ['active', 'suspended', 'deleted'])->default('active');
            $table->unsignedTinyInteger('free_assets_quota')->default(3);
            $table->timestamps();

            $table->index('kyc_status', 'idx_users_kyc');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
