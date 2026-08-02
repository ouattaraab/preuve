<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages modifiables depuis le back-office, sans redéploiement.
 *
 * Réservé à ce qui doit pouvoir changer en exploitation — fournisseur SMS et
 * ses identifiants, par exemple. Rien de ce qui touche aux règles métier
 * absolues n'a vocation à passer par ici : une matrice de transitions ou un
 * plafond de tentatives modifiable depuis une interface web serait une porte
 * dérobée sur les garanties du produit.
 *
 * Les valeurs marquées `is_secret` sont chiffrées au repos (APP_KEY) : ces
 * lignes contiennent des clés d'API de fournisseurs de SMS, qui permettraient
 * d'envoyer des messages aux frais de la plateforme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            // Sérialisée en JSON : un réglage peut être un scalaire comme une
            // structure (en-têtes HTTP, gabarit de corps de requête).
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            // Qui a changé quoi est déjà dans la chaîne d'audit ; cette colonne
            // ne sert qu'à l'afficher sans relire tout le journal.
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
