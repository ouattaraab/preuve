<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Justificatifs déposés à l'appui d'un enregistrement (ST-0207, ST-0208).
 *
 * Ce sont eux qui font monter le niveau de fiabilité : une facture ou une carte
 * grise acceptée fait passer de « Déclaré » à « Documenté ». Leur revue est
 * donc une décision à effet direct sur ce qu'un acheteur voit, et elle est
 * tracée : qui a revu, quand, et pourquoi en cas de rejet.
 *
 * `file_sha256` est l'empreinte du fichier au moment du dépôt. Sans elle, un
 * document accepté puis remplacé dans le stockage objet ferait tenir un
 * niveau de fiabilité sur une pièce qui n'est plus celle qui a été revue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->enum('doc_type', ['invoice', 'registration_card', 'acd', 'police_report', 'photo', 'other']);
            // Clé objet dans le stockage chiffré au repos, jamais le contenu.
            $table->string('file_ref', 255);
            $table->char('file_sha256', 64);
            // Extraction OCR minimisée : jamais la pièce entière, seulement les
            // champs utiles au rapprochement (Loi 2013-450, minimisation).
            $table->json('ocr_payload')->nullable();
            $table->enum('review_status', ['pending', 'accepted', 'rejected', 'suspected_forgery'])
                ->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            // Motif de rejet, obligatoire au rejet côté service (ST-0208) : un
            // refus sans raison est incontestable, donc arbitraire.
            $table->string('review_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'doc_type'], 'idx_docs_asset');
            $table->index('review_status', 'idx_docs_review');
        });

        Schema::table('assets', function (Blueprint $table): void {
            // Extension au schéma de référence : le niveau « Vérifié » (F3)
            // suppose un contrôle croisé du back-office, qui doit laisser une
            // trace ailleurs que dans le niveau lui-même — sans quoi rien ne
            // distinguerait un F3 accordé d'un F3 calculé par erreur.
            $table->dateTime('trust_verified_at')->nullable()->after('trust_level');
            $table->unsignedBigInteger('trust_verified_by')->nullable()->after('trust_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn(['trust_verified_at', 'trust_verified_by']);
        });

        Schema::dropIfExists('asset_documents');
    }
};
