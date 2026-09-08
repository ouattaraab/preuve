<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            // Représentant légal, KYC requis
            $table->foreignId('owner_user_id')->constrained('users');
            $table->string('legal_name', 200);
            $table->string('rccm_number', 50)->unique();
            $table->enum('business_type', ['car_rental', 'dealer', 'other'])->default('car_rental');
            // Validation back-office obligatoire avant activation
            $table->enum('validation_status', ['pending', 'validated', 'rejected'])->default('pending');
            $table->timestamp('validated_at')->nullable();
            $table->unsignedTinyInteger('free_fleet_quota')->default(3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
