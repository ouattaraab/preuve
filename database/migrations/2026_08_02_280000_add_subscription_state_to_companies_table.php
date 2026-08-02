<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État d'abonnement d'une flotte (ST-0805).
 *
 * LA SUSPENSION EST DOUCE, et c'est la seule forme acceptable. Une société qui
 * n'a pas réglé son abonnement ne peut plus AJOUTER de véhicules — mais ceux
 * qu'elle a déjà enregistrés restent protégés, consultables et alertés.
 *
 * Retirer la protection d'un parc pour une facture impayée punirait les
 * véhicules, pas le débiteur : un acheteur consultant un véhicule volé
 * recevrait « bien inconnu » parce que son propriétaire a du retard de
 * paiement. La plateforme perdrait alors exactement ce qui fait sa valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->enum('subscription_status', ['trial', 'active', 'past_due', 'read_only'])
                ->default('trial')
                ->after('validated_at');
            $table->dateTime('subscription_due_at')->nullable()->after('subscription_status');
            $table->dateTime('last_dunning_at')->nullable()->after('subscription_due_at');
            $table->unsignedTinyInteger('dunning_count')->default(0)->after('last_dunning_at');

            $table->index(['subscription_status', 'subscription_due_at'], 'idx_companies_subscription');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex('idx_companies_subscription');
            $table->dropColumn([
                'subscription_status', 'subscription_due_at', 'last_dunning_at', 'dunning_count',
            ]);
        });
    }
};
