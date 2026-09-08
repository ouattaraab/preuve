<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            // Référence publique opaque, ex. PRV-9F3K2A7Q
            $table->char('public_ref', 12)->unique();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->foreignId('company_id')->nullable()->constrained('companies');
            // Clé de catégorie dynamique — plus d'ENUM figé (décision D6)
            $table->string('asset_category_key', 40);
            $table->enum('identifier_type', ['vin', 'plate', 'imei', 'serial', 'lot_number']);
            $table->string('identifier_raw', 64);
            // Identifiant canonique : colonne réelle et indexée, jamais dans le JSON
            $table->string('identifier_normalized', 64);
            // 1 = actif, NULL = archivé. Support de l'unicité partielle.
            $table->tinyInteger('active_flag')->nullable()->default(1);
            // Champs propres à la catégorie
            $table->json('attributes')->nullable();
            $table->enum('trust_level', ['F1', 'F2', 'F3'])->default('F1');
            $table->enum('life_status', ['V-ACT', 'V-PRV', 'V-LOC', 'V-VTE', 'V-VOL', 'V-LIT', 'V-FDV'])
                ->default('V-PRV');
            $table->timestamp('provisional_until')->nullable();
            $table->timestamp('stolen_declared_at')->nullable();
            // 1 si un récépissé de plainte a été fourni dans le délai imparti
            $table->boolean('stolen_consolidated')->default(false);
            // Signal temporel public, jamais modifiable
            $table->timestamp('registered_at');
            $table->timestamps();

            // Règle métier absolue n° 3 : un identifiant = un enregistrement actif.
            // MariaDB, comme MySQL, considère les NULL comme distincts : autant
            // d'archives que nécessaire, un seul actif.
            $table->unique(['identifier_normalized', 'active_flag'], 'uq_assets_identifier_active');
            $table->index(['owner_user_id', 'life_status'], 'idx_assets_owner');
            $table->index(['identifier_normalized', 'life_status', 'trust_level'], 'idx_assets_lookup');
            $table->index('asset_category_key', 'idx_assets_category');
        });

        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT chk_assets_active
             CHECK (active_flag IS NULL OR active_flag = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
