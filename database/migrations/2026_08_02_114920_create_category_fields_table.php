<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_category_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 120);
            $table->enum('type', ['identifier', 'text', 'number', 'date', 'photo', 'select']);
            $table->boolean('is_required')->default(false);
            // Le champ extrait vers assets.identifier_normalized. Exactement un par catégorie.
            $table->boolean('is_canonical_identifier')->default(false);
            // Règle de validation Laravel supplémentaire, ex. « size:15 »
            $table->string('validation_rule', 120)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['asset_category_id', 'key'], 'uq_field_par_categorie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_fields');
    }
};
