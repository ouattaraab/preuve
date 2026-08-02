<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements et achats de rapports (EP-08).
 *
 * `uq (provider, provider_ref)` porte à elle seule l'idempotence des webhooks
 * (ST-0806) : un opérateur de paiement mobile renvoie le même événement
 * plusieurs fois — c'est le comportement normal d'un réseau peu fiable, pas une
 * anomalie. Sans cette contrainte, un rapport serait crédité deux fois pour un
 * seul paiement, ou pire, un abonnement prolongé indûment.
 *
 * `chk_payments_identity` interdit un paiement sans payeur identifiable :
 * compte connecté, OU nom + e-mail + téléphone. La règle produit veut que
 * personne n'accède à un rapport détaillé sans être identifié — la contrainte
 * la met en base plutôt que dans le seul code applicatif, qui peut être
 * contourné par un accès direct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('buyer_name', 150)->nullable();
            $table->string('buyer_email', 150)->nullable();
            $table->string('buyer_phone', 20)->nullable();
            $table->enum('purpose', ['detailed_report', 'claim_fee', 'asset_slot', 'fleet_subscription']);
            $table->unsignedBigInteger('related_id')->nullable();
            $table->unsignedInteger('amount_fcfa');
            // CinetPay est INTERDIT par la stack : il ne figure pas dans
            // l'énumération, ce qui rend son usage impossible en base et pas
            // seulement déconseillé.
            $table->enum('provider', ['paystack', 'pawapay_wave', 'pawapay_om', 'pawapay_momo']);
            $table->string('provider_ref', 100)->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded'])->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref'], 'uq_payments_provider_ref');
            $table->index(['user_id', 'purpose'], 'idx_payments_user');
            $table->index('buyer_phone', 'idx_payments_buyer_phone');
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT chk_payments_identity CHECK (
                user_id IS NOT NULL
                OR (buyer_name IS NOT NULL AND buyer_email IS NOT NULL AND buyer_phone IS NOT NULL)
            )'
        );

        Schema::create('report_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('payment_id')->constrained('payments');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('access_token', 40);
            $table->dateTime('expires_at');
            $table->dateTime('first_access_at')->nullable();
            $table->unsignedSmallInteger('access_count')->default(0);
            $table->timestamps();

            $table->unique('access_token', 'uq_report_token');
            $table->index('asset_id', 'idx_report_asset');
            $table->index('user_id', 'idx_report_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_purchases');
        Schema::dropIfExists('payments');
    }
};
