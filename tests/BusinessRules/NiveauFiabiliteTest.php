<?php

declare(strict_types=1);

use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetStatusHistory;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TrustLevelEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Niveaux de fiabilité (systemPatterns §1) :
 *   F1 Déclaré non vérifié · F2 Documenté · F3 Vérifié
 *
 * Ce fichier porte sa PROPRE lecture des conditions, écrite à la main depuis
 * le cadrage — comme la matrice des transitions. Le niveau affiché est ce sur
 * quoi un acheteur fonde sa décision : il ne doit jamais pouvoir monter d'un
 * cran sans que quelqu'un ait réellement vérifié quelque chose.
 *
 * N'utilise pas RefreshDatabase : le recalcul écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('asset_documents')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerFiabilite();
});
afterEach(fn () => nettoyerFiabilite());

function nettoyerFiabilite(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'notifications', 'asset_documents', 'asset_status_history', 'assets', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function bienEvalue(bool $kycVerifie = false): Asset
{
    $proprietaire = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    if ($kycVerifie) {
        $proprietaire->forceFill(['kyc_status' => 'verified', 'kyc_verified_at' => now()])->save();
    }

    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $proprietaire->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'identifier_normalized' => '1M8GDM9AXKP0'.random_int(10000, 99999),
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonth(),
    ]);
}

function deposer(Asset $bien, DocumentType $type, DocumentReviewStatus $statut): AssetDocument
{
    return AssetDocument::create([
        'asset_id' => $bien->id,
        'uploaded_by' => $bien->owner_user_id,
        'doc_type' => $type,
        'file_ref' => 'documents/'.bin2hex(random_bytes(8)).'.pdf',
        'file_sha256' => hash('sha256', bin2hex(random_bytes(8))),
        'review_status' => $statut,
        'reviewed_at' => $statut->isFinal() ? now() : null,
    ]);
}

it('plancher à Déclaré non vérifié pour tout bien enregistré', function (): void {
    // Il n'existe pas de niveau inférieur : un enregistrement seul vaut F1.
    expect(app(TrustLevelEngine::class)->evaluate(bienEvalue()))->toBe(TrustLevel::Declared);
});

it('ne monte pas à Documenté sur un justificatif encore en attente', function (): void {
    // Le niveau ne doit jamais reposer sur une pièce que personne n'a regardée :
    // sinon il suffirait de téléverser n'importe quoi pour paraître documenté.
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Pending);

    expect(app(TrustLevelEngine::class)->evaluate($bien))->toBe(TrustLevel::Declared);
});

it('ne monte pas à Documenté sans KYC complet du détenteur', function (): void {
    // Une facture acceptée sans savoir à qui appartient le compte ne prouve
    // rien : c'est le rapprochement des deux qui fait la preuve.
    $bien = bienEvalue(kycVerifie: false);
    deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Accepted);

    expect(app(TrustLevelEngine::class)->evaluate($bien))->toBe(TrustLevel::Declared);
});

it('monte à Documenté sur justificatif accepté et KYC complet', function (): void {
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Accepted);

    expect(app(TrustLevelEngine::class)->evaluate($bien))->toBe(TrustLevel::Documented);
});

it('n\'accepte comme preuve de propriété que les pièces qui en sont une', function (): void {
    // Une photo montre le bien, un récépissé de plainte atteste un incident :
    // ni l'une ni l'autre ne dit à qui il appartient.
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Photo, DocumentReviewStatus::Accepted);
    deposer($bien, DocumentType::PoliceReport, DocumentReviewStatus::Accepted);

    expect(app(TrustLevelEngine::class)->evaluate($bien))->toBe(TrustLevel::Declared);
});

it('ignore une pièce rejetée ou suspectée de falsification', function (): void {
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Rejected);
    deposer($bien, DocumentType::RegistrationCard, DocumentReviewStatus::SuspectedForgery);

    expect(app(TrustLevelEngine::class)->evaluate($bien))->toBe(TrustLevel::Declared);
});

it('ne monte à Vérifié que sur contrôle croisé du back-office', function (): void {
    // F3 n'est jamais calculable : il suppose qu'un agent a rapproché la pièce
    // d'une source extérieure. Sans cette trace, un bien parfaitement documenté
    // reste F2.
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::RegistrationCard, DocumentReviewStatus::Accepted);

    expect(app(TrustLevelEngine::class)->evaluate($bien))->toBe(TrustLevel::Documented);

    $bien->forceFill(['trust_verified_at' => now(), 'trust_verified_by' => 1])->save();

    expect(app(TrustLevelEngine::class)->evaluate($bien->fresh()))->toBe(TrustLevel::Verified);
});

it('ne laisse pas un contrôle back-office suppléer les conditions de Documenté', function (): void {
    // Un agent qui coche « vérifié » sur un bien sans justificatif ne crée pas
    // une preuve : il ne peut que confirmer ce qui existe.
    $bien = bienEvalue(kycVerifie: false);
    $bien->forceFill(['trust_verified_at' => now(), 'trust_verified_by' => 1])->save();

    expect(app(TrustLevelEngine::class)->evaluate($bien->fresh()))->toBe(TrustLevel::Declared);
});

it('applique le niveau calculé et l\'inscrit à l\'historique', function (): void {
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Accepted);

    $applique = app(TrustLevelEngine::class)->recalculate($bien);

    $ligne = AssetStatusHistory::where('asset_id', $bien->id)->sole();

    expect($applique)->toBe(TrustLevel::Documented)
        ->and($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented)
        ->and($ligne->from_trust)->toBe(TrustLevel::Declared)
        ->and($ligne->to_trust)->toBe(TrustLevel::Documented)
        // Le statut de vie ne bouge pas : les deux dimensions sont
        // indépendantes.
        ->and($ligne->from_status)->toBe(LifeStatus::Active)
        ->and($ligne->to_status)->toBe(LifeStatus::Active);
});

it('n\'écrit rien quand le niveau ne change pas', function (): void {
    // Un recalcul est déclenché à chaque événement : s'il historisait à vide,
    // il noierait la chronologie du bien.
    $bien = bienEvalue();

    app(TrustLevelEngine::class)->recalculate($bien);

    expect(AssetStatusHistory::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('redescend le niveau quand une pièce est invalidée après coup', function (): void {
    // Essentiel contre la fraude : une falsification découverte plus tard doit
    // retirer le niveau qu'elle avait fait gagner, sinon l'acheteur suivant se
    // fie à une preuve qui n'existe plus.
    $bien = bienEvalue(kycVerifie: true);
    $piece = deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Accepted);

    app(TrustLevelEngine::class)->recalculate($bien);
    expect($bien->fresh()?->trust_level)->toBe(TrustLevel::Documented);

    $piece->forceFill(['review_status' => DocumentReviewStatus::SuspectedForgery])->save();

    expect(app(TrustLevelEngine::class)->recalculate($bien->fresh()))->toBe(TrustLevel::Declared)
        ->and($bien->fresh()?->trust_level)->toBe(TrustLevel::Declared);
});

it('journalise le changement de niveau avec la version des règles', function (): void {
    // ST-0401 : les règles sont versionnées. Sans cette trace, un niveau
    // attribué hier serait indistinguable d'un niveau attribué sous des règles
    // différentes — et donc incontestable.
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Invoice, DocumentReviewStatus::Accepted);

    app(TrustLevelEngine::class)->recalculate($bien);

    $entree = AuditLog::where('action', 'asset.trust_level_changed')->sole();

    expect($entree->entity_id)->toBe($bien->id)
        ->and($entree->payload['from'] ?? null)->toBe('F1')
        ->and($entree->payload['to'] ?? null)->toBe('F2')
        ->and($entree->payload['rules_version'] ?? null)->not->toBeNull();
});

it('expose ce qui manque pour atteindre le niveau suivant (ST-0207)', function (): void {
    // La jauge doit expliciter le bénéfice ET le chemin : « ajoutez votre carte
    // grise » vaut mieux qu'un pourcentage muet.
    $bien = bienEvalue(kycVerifie: false);

    $jauge = app(TrustLevelEngine::class)->progress($bien);

    expect($jauge['current'])->toBe('F1')
        ->and($jauge['next'])->toBe('F2')
        ->and($jauge['missing'])->toContain('proof_of_ownership')
        ->and($jauge['missing'])->toContain('kyc_verified');
});

it('ne propose plus rien à atteindre au niveau maximal', function (): void {
    $bien = bienEvalue(kycVerifie: true);
    deposer($bien, DocumentType::Acd, DocumentReviewStatus::Accepted);
    $bien->forceFill(['trust_verified_at' => now(), 'trust_verified_by' => 1])->save();

    $jauge = app(TrustLevelEngine::class)->progress($bien->fresh());

    expect($jauge['current'])->toBe('F3')
        ->and($jauge['next'])->toBeNull()
        ->and($jauge['missing'])->toBe([]);
});
