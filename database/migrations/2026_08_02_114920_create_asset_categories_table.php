<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            // Clé stable utilisée par l'application mobile — jamais renommée
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            // Emoji affiché dans l'application et le back-office
            $table->string('icon', 16)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position'], 'idx_categories_publication');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
