<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transferts de propriété à double validation (EP-06, §4.4).
 *
 * Deux colonnes s'ajoutent au schéma de référence, et chacune répare un manque
 * qui rendrait le parcours impossible :
 *
 * - `previous_life_status` : une annulation ou une expiration doit ramener le
 *   bien à son statut ANTÉRIEUR. Sans mémoire de ce statut, impossible de
 *   savoir s'il faut revenir à « Actif » ou à « En location » — et un véhicule
 *   de loueur reviendrait « Actif », c'est-à-dire vendable sans avertissement.
 * - `assets.previous_asset_id` : le transfert archive l'ancien enregistrement
 *   et en crée un nouveau (règle métier absolue n° 3). Sans ce lien, la chaîne
 *   des détenteurs (ST-0603) serait irreconstituable, alors qu'elle fonde le
 *   rapport détaillé payant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('from_user_id')->constrained('users');
            // NULL tant que l'acheteur n'a pas créé son compte : on transfère
            // vers un numéro de téléphone, pas vers un compte préexistant —
            // exiger de l'acheteur qu'il s'inscrive AVANT que le vendeur
            // initie ferait échouer la vente au marché.
            $table->unsignedBigInteger('to_user_id')->nullable();
            $table->string('to_phone', 20);
            $table->enum('status', ['initiated', 'buyer_confirmed', 'completed', 'cancelled', 'expired'])
                ->default('initiated');
            $table->enum('previous_life_status', ['V-ACT', 'V-PRV', 'V-LOC', 'V-VTE', 'V-VOL', 'V-LIT', 'V-FDV']);
            $table->dateTime('seller_otp_at')->nullable();
            $table->dateTime('buyer_otp_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            // Enregistrement né du transfert, une fois celui-ci finalisé.
            $table->unsignedBigInteger('created_asset_id')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status'], 'idx_transfers_asset');
            $table->index('to_phone', 'idx_transfers_to_phone');
            $table->index(['status', 'expires_at'], 'idx_transfers_expiry');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('previous_asset_id')->nullable()->after('company_id');
            $table->index('previous_asset_id', 'idx_assets_previous');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex('idx_assets_previous');
            $table->dropColumn('previous_asset_id');
        });

        Schema::dropIfExists('transfers');
    }
};
