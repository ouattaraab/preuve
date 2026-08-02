<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réclamations et arbitrage (EP-05, §4.5).
 *
 * C'est le seul chemin vers « Litige en cours » : sans réclamation recevable,
 * un bien ne peut pas être gelé. C'est aussi le seul recours d'une victime
 * dont le bien a été enregistré par quelqu'un d'autre — la fraude que la
 * plateforme existe pour traiter.
 *
 * `fee_payment_id` reste nul tant qu'EP-08 n'a pas apporté les paiements. Les
 * frais de dossier sont prévus (2000/5000 FCFA, remboursés si gain) mais ne
 * conditionnent PAS le dépôt : bloquer une victime sur un paiement
 * indisponible reviendrait à fermer le seul recours qu'elle ait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('claimant_user_id')->constrained('users');
            $table->enum('status', [
                'draft', 'submitted', 'admissible', 'contradictory',
                'under_review', 'decided', 'appealed', 'closed',
            ])->default('draft');
            $table->unsignedBigInteger('fee_payment_id')->nullable();
            $table->boolean('fee_refunded')->default(false);
            // Fin du délai contradictoire laissé au mis en cause (J+15).
            $table->dateTime('respondent_deadline')->nullable();
            $table->enum('decision', ['transfer_to_claimant', 'keep_current', 'unresolved'])->nullable();
            // Motivation obligatoire côté service : une décision de propriété
            // sans motif est incontestable, donc arbitraire.
            $table->text('decision_reason')->nullable();
            $table->unsignedSmallInteger('claimant_score')->nullable();
            $table->unsignedSmallInteger('respondent_score')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            // Appel interne unique, instruit par un agent différent.
            $table->unsignedBigInteger('appeal_of')->nullable();
            // Empreinte de l'export remis aux parties : c'est elle qui rend
            // l'export opposable devant un tiers.
            $table->char('export_sha256', 64)->nullable();
            $table->string('export_ref', 255)->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status'], 'idx_claims_asset');
            $table->index('claimant_user_id', 'idx_claims_claimant');
            $table->index(['status', 'respondent_deadline'], 'idx_claims_deadline');
        });

        Schema::create('claim_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims');
            $table->enum('party', ['claimant', 'respondent']);
            $table->enum('evidence_type', [
                'official_named_doc', 'police_report', 'invoice',
                'anteriority', 'account_history', 'photo_context',
            ]);
            $table->string('file_ref', 255)->nullable();
            $table->char('file_sha256', 64)->nullable();
            // Les dates des pièces priment sur la date plateforme (§5.2) : un
            // enregistrement récent ne vaut rien contre une carte grise
            // antérieure.
            $table->date('document_date')->nullable();
            // 0 si falsification suspectée : la pièce compte alors pour rien,
            // et l'affaire est escaladée.
            $table->unsignedTinyInteger('weight_applied')->default(0);
            $table->string('agent_note', 500)->nullable();
            $table->timestamps();

            $table->index(['claim_id', 'party'], 'idx_evidences_claim');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_evidences');
        Schema::dropIfExists('claims');
    }
};
