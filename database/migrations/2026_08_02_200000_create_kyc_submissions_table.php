<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossiers de vérification d'identité (ST-0103).
 *
 * Extension au schéma de référence, qui portait le résultat du KYC sur `users`
 * mais nulle part les pièces ni l'historique des tentatives. Or un dossier
 * rejeté doit pouvoir être resoumis corrigé, et la trace du refus doit
 * survivre — sinon l'agent suivant réexamine à l'aveugle une pièce déjà écartée.
 *
 * Les images ne sont JAMAIS stockées ici, seulement leurs clés objet sur le
 * bucket chiffré au repos. Le numéro de pièce n'y figure sous aucune forme
 * lisible : il ne vit que haché, sur `users` (règle métier absolue n° 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            // Clés objet du recto, du verso et du selfie.
            $table->string('id_front_ref', 255);
            $table->string('id_back_ref', 255);
            $table->string('selfie_ref', 255);
            // Empreintes figées au dépôt : une pièce acceptée puis remplacée
            // dans le stockage ferait tenir une identité vérifiée sur un
            // document qui n'est plus celui qui a été examiné.
            $table->char('id_front_sha256', 64);
            $table->char('id_back_sha256', 64);
            $table->char('selfie_sha256', 64);
            // Extraction OCR MINIMISÉE : ni le numéro de pièce, ni l'image.
            // Seulement de quoi rapprocher le dossier du compte.
            $table->json('ocr_payload')->nullable();
            // Score de vivacité du selfie quand un fournisseur le rend ; nul
            // tant que la vivacité est appréciée à l'œil par un agent.
            $table->unsignedTinyInteger('liveness_score')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            // Motif obligatoire au rejet, côté service : un refus d'identité
            // sans raison est incontestable et bloque l'utilisateur sans
            // recours.
            $table->string('review_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_kyc_user');
            $table->index('status', 'idx_kyc_status');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Détection d'usurpation : deux comptes ne peuvent pas s'appuyer
            // sur la même pièce d'identité. L'index n'est pas unique — un rejet
            // suivi d'une resoumission légitime réutilise le même numéro — mais
            // il rend la recherche immédiate.
            $table->index('kyc_id_number_hash', 'idx_users_kyc_id_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_users_kyc_id_hash');
        });

        Schema::dropIfExists('kyc_submissions');
    }
};
