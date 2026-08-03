<?php

declare(strict_types=1);

use App\Enums\ClaimDecision;
use App\Enums\ClaimStatus;
use App\Enums\EvidenceType;
use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\ClaimEvidence;
use App\Models\Notification;
use App\Models\User;
use App\Services\ClaimArbitrationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * EP-05 (ST-0501 à ST-0506) et grille d'arbitrage (systemPatterns §3).
 *
 * L'équilibre à tenir : filtrer les dossiers vides SANS écarter les victimes.
 * Une victime dont les papiers ont été volés avec le bien doit pouvoir être
 * entendue, et un bien contesté doit cesser d'être vendable immédiatement.
 *
 * N'utilise pas RefreshDatabase : l'arbitrage écrit dans la chaîne d'audit.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('claims')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerReclamations();
    Storage::fake('s3');

    $this->arbitrage = app(ClaimArbitrationService::class);
});
afterEach(fn () => nettoyerReclamations());

function nettoyerReclamations(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'audit_log', 'notifications', 'claim_evidences', 'claims', 'transfers',
        'asset_status_history', 'assets', 'otp_codes', 'users',
    ] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function partie(bool $kyc = true): User
{
    $utilisateur = User::create(['phone' => '+2250700'.random_int(100000, 999999)]);

    if ($kyc) {
        $utilisateur->forceFill([
            'kyc_status' => KycStatus::Verified->value,
            'kyc_verified_at' => now()->subMonth(),
        ])->save();
    }

    return $utilisateur;
}

function agentArbitre(): User
{
    $utilisateur = User::create(['phone' => '+2250799'.random_int(100000, 999999)]);
    $utilisateur->forceFill(['role' => UserRole::Agent])->save();

    return $utilisateur;
}

function bienConteste(User $detenteur): Asset
{
    return Asset::create([
        'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
        'owner_user_id' => $detenteur->id,
        'asset_category_key' => 'moto',
        'identifier_type' => 'vin',
        'identifier_raw' => '1M8GDM9AXKP042788',
        'identifier_normalized' => '1M8GDM9AXKP042788',
        'active_flag' => 1,
        'attributes' => [],
        'trust_level' => TrustLevel::Declared,
        'life_status' => LifeStatus::Active,
        'registered_at' => now()->subMonths(2),
    ]);
}

/** Dossier recevable d'emblée : une carte grise nominative suffit. */
function dossierRecevable(User $reclamant, Asset $bien): Claim
{
    $dossier = test()->arbitrage->open($bien, $reclamant);
    test()->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::OfficialNamedDoc);

    return test()->arbitrage->submit($dossier->fresh() ?? $dossier);
}

it('exige une identité vérifiée pour réclamer', function (): void {
    // Contester la propriété d'autrui engage : un dossier anonyme serait un
    // outil de nuisance gratuit.
    $bien = bienConteste(partie());

    expect(fn () => $this->arbitrage->open($bien, partie(kyc: false)))
        ->toThrow(DomainException::class);
});

it('refuse de réclamer son propre bien', function (): void {
    $detenteur = partie();

    expect(fn () => $this->arbitrage->open(bienConteste($detenteur), $detenteur))
        ->toThrow(DomainException::class);
});

it('reprend le brouillon plutôt que d\'empiler les dossiers', function (): void {
    // Un dossier interrompu par une coupure réseau doit se retrouver (CT-05).
    $reclamant = partie();
    $bien = bienConteste(partie());

    $premier = $this->arbitrage->open($bien, $reclamant);
    $second = $this->arbitrage->open($bien, $reclamant);

    expect($second->id)->toBe($premier->id)
        ->and(Claim::count())->toBe(1);
});

it('ouvre d\'emblée le dossier sur un document nominatif', function (): void {
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    expect($dossier->status)->toBe(ClaimStatus::Contradictory);
});

it('ouvre d\'emblée le dossier sur un récépissé de plainte', function (): void {
    $bien = bienConteste(partie());
    $dossier = $this->arbitrage->open($bien, partie());
    $this->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::PoliceReport);

    expect($this->arbitrage->submit($dossier->fresh() ?? $dossier)->status)
        ->toBe(ClaimStatus::Contradictory);
});

it('renvoie en revue manuelle plutôt qu\'au rejet', function (): void {
    // Une victime qui n'a pas encore pu porter plainte, ou dont les papiers
    // ont été volés avec le bien, doit pouvoir être entendue.
    $bien = bienConteste(partie());
    $dossier = $this->arbitrage->open($bien, partie());
    $this->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::PhotoContext);

    $depose = $this->arbitrage->submit($dossier->fresh() ?? $dossier);

    expect($depose->status)->toBe(ClaimStatus::Submitted)
        ->and($bien->fresh()?->life_status)->toBe(LifeStatus::Active);
});

it('gèle le bien dès la recevabilité', function (): void {
    // Attendre l'issue de l'instruction laisserait au fraudeur les semaines
    // dont il a besoin pour vendre.
    $bien = bienConteste(partie());
    dossierRecevable(partie(), $bien);

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Disputed);
});

it('prévient le mis en cause sans rien lui dire du réclamant', function (): void {
    // Règle métier absolue n° 4 : il apprend qu'une réclamation existe et ce
    // qu'il doit produire, jamais qui le conteste.
    $detenteur = partie();
    $bien = bienConteste($detenteur);
    $reclamant = partie();
    $reclamant->forceFill(['full_name' => 'Awa Koné'])->save();

    dossierRecevable($reclamant, $bien);

    $alerte = Notification::where('user_id', $detenteur->id)->sole();

    expect($alerte->body)->not->toContain('Awa Koné')
        ->and($alerte->body)->not->toContain($reclamant->phone)
        ->and($alerte->body)->toContain('15 jours');
});

it('ouvre un délai contradictoire de quinze jours', function (): void {
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    expect($dossier->respondent_deadline?->toDateString())
        ->toBe(now()->addDays(15)->toDateString());
});

it('exige un motif au refus de recevabilité', function (): void {
    $bien = bienConteste(partie());
    $dossier = $this->arbitrage->open($bien, partie());
    $this->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::PhotoContext);
    $depose = $this->arbitrage->submit($dossier->fresh() ?? $dossier);

    expect(fn () => $this->arbitrage->markAdmissible($depose, agentArbitre(), false))
        ->toThrow(DomainException::class);
});

it('applique les poids de la grille', function (): void {
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    $this->arbitrage->addEvidence($dossier, 'claimant', EvidenceType::Invoice);
    $this->arbitrage->addEvidence($dossier, 'respondent', EvidenceType::AccountHistory);

    $scores = $this->arbitrage->score($dossier->fresh() ?? $dossier);

    expect($scores['claimant'])->toBe(55)
        ->and($scores['respondent'])->toBe(5)
        ->and($scores['gap'])->toBe(50);
});

it('suggère « litige non tranché » en deçà de vingt points d\'écart', function (): void {
    // La plateforme reconnaît que les preuves ne départagent pas : trancher
    // reviendrait à décider d'un droit de propriété sur une intime conviction.
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    $this->arbitrage->addEvidence($dossier, 'respondent', EvidenceType::OfficialNamedDoc);

    $scores = $this->arbitrage->score($dossier->fresh() ?? $dossier);

    expect($scores['gap'])->toBe(0)
        ->and($scores['suggested'])->toBe(ClaimDecision::Unresolved);
});

it('annule le poids d\'une pièce suspectée de falsification', function (): void {
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);
    $piece = ClaimEvidence::sole();

    $this->arbitrage->discardEvidence($piece, agentArbitre(), 'Tampon incohérent avec le millésime.');

    expect($piece->fresh()?->weight_applied)->toBe(0)
        ->and($this->arbitrage->score($dossier->fresh() ?? $dossier)['claimant'])->toBe(0);
});

it('exige une note pour écarter une pièce', function (): void {
    $bien = bienConteste(partie());
    dossierRecevable(partie(), $bien);

    expect(fn () => $this->arbitrage->discardEvidence(ClaimEvidence::sole(), agentArbitre(), '  '))
        ->toThrow(DomainException::class);
});

it('exige une motivation pour toute décision', function (): void {
    // Une décision de propriété sans explication est incontestable, donc
    // arbitraire.
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    expect(fn () => $this->arbitrage->decide($dossier, agentArbitre(), ClaimDecision::KeepCurrent, ''))
        ->toThrow(DomainException::class);
});

it('transfère le bien au réclamant quand la réclamation est fondée', function (): void {
    $detenteur = partie();
    $bien = bienConteste($detenteur);
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, $bien);

    $this->arbitrage->decide(
        $dossier,
        agentArbitre(),
        ClaimDecision::TransferToClaimant,
        'Carte grise nominative antérieure à l\'enregistrement contesté.',
    );

    $actifs = Asset::where('identifier_normalized', '1M8GDM9AXKP042788')->whereNotNull('active_flag')->get();

    expect($actifs)->toHaveCount(1)
        ->and($actifs->first()?->owner_user_id)->toBe($reclamant->id)
        ->and($actifs->first()?->life_status)->toBe(LifeStatus::Active)
        ->and($bien->fresh()?->active_flag)->toBeNull();
});

it('lève le gel quand le détenteur est confirmé', function (): void {
    $detenteur = partie();
    $bien = bienConteste($detenteur);
    $dossier = dossierRecevable(partie(), $bien);

    $this->arbitrage->decide(
        $dossier,
        agentArbitre(),
        ClaimDecision::KeepCurrent,
        'Le réclamant ne produit aucune pièce nominative.',
    );

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Active)
        ->and($bien->fresh()?->owner_user_id)->toBe($detenteur->id);
});

it('laisse le bien gelé quand le litige n\'est pas tranché', function (): void {
    // Ne pas trancher n'est pas innocenter : laisser repartir un bien contesté
    // à la vente reviendrait à trancher en faveur du détenteur.
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    $this->arbitrage->decide(
        $dossier,
        agentArbitre(),
        ClaimDecision::Unresolved,
        'Les deux parties produisent une pièce nominative : écart insuffisant.',
    );

    expect($bien->fresh()?->life_status)->toBe(LifeStatus::Disputed);
});

it('remet aux deux parties un export horodaté et empreint', function (): void {
    $detenteur = partie();
    $bien = bienConteste($detenteur);
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, $bien);

    $this->arbitrage->decide(
        $dossier,
        agentArbitre(),
        ClaimDecision::Unresolved,
        'Écart de preuves insuffisant.',
    );

    $tranche = $dossier->fresh();

    expect($tranche?->export_sha256)->toHaveLength(64)
        ->and(Notification::where('user_id', $reclamant->id)->count())->toBeGreaterThan(0)
        ->and(Notification::where('user_id', $detenteur->id)->count())->toBeGreaterThan(0);

    $contenu = Storage::disk('s3')->get((string) $tranche?->export_ref);

    expect(hash('sha256', (string) $contenu))->toBe($tranche?->export_sha256)
        // Ne vaut pas jugement : le dire protège les parties autant que la
        // plateforme.
        ->and($contenu)->toContain('ne vaut pas jugement de propriété');
});

it('n\'écrit aucune identité dans l\'export', function (): void {
    // Le registre ne divulgue jamais les identités, y compris entre parties à
    // un même dossier.
    $detenteur = partie();
    $detenteur->forceFill(['full_name' => 'Ibrahim Traoré'])->save();
    $bien = bienConteste($detenteur);
    $reclamant = partie();
    $reclamant->forceFill(['full_name' => 'Awa Koné'])->save();
    $dossier = dossierRecevable($reclamant, $bien);

    $this->arbitrage->decide($dossier, agentArbitre(), ClaimDecision::Unresolved, 'Écart insuffisant.');

    $contenu = (string) Storage::disk('s3')->get((string) $dossier->fresh()?->export_ref);

    expect($contenu)->not->toContain('Awa Koné')
        ->and($contenu)->not->toContain('Ibrahim Traoré')
        ->and($contenu)->not->toContain($reclamant->phone);
});

it('journalise la décision avec les scores', function (): void {
    $bien = bienConteste(partie());
    $dossier = dossierRecevable(partie(), $bien);

    $this->arbitrage->decide($dossier, agentArbitre(), ClaimDecision::KeepCurrent, 'Pièces insuffisantes.');

    $entree = AuditLog::where('action', 'claim.decided')->sole();

    expect($entree->payload['claimant_score'] ?? null)->toBe(40)
        ->and($entree->payload['decision'] ?? null)->toBe('keep_current')
        ->and($entree->payload['reason'] ?? null)->toBe('Pièces insuffisantes.');
});

it('n\'ouvre qu\'un seul appel', function (): void {
    $bien = bienConteste(partie());
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, $bien);
    $this->arbitrage->decide($dossier, agentArbitre(), ClaimDecision::KeepCurrent, 'Pièces insuffisantes.');

    $enAppel = $this->arbitrage->appeal($dossier->fresh() ?? $dossier, $reclamant);

    expect($enAppel->status)->toBe(ClaimStatus::Appealed)
        ->and(fn () => $this->arbitrage->appeal($enAppel, $reclamant))->toThrow(DomainException::class);
});

it('confie l\'appel à un autre agent que celui de la première décision', function (): void {
    // Se relire soi-même n'est pas un recours.
    $bien = bienConteste(partie());
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, $bien);
    $premierAgent = agentArbitre();

    $this->arbitrage->decide($dossier, $premierAgent, ClaimDecision::KeepCurrent, 'Pièces insuffisantes.');
    $enAppel = $this->arbitrage->appeal($dossier->fresh() ?? $dossier, $reclamant);

    expect(fn () => $this->arbitrage->decide($enAppel, $premierAgent, ClaimDecision::Unresolved, 'Réexamen.'))
        ->toThrow(DomainException::class);

    $second = $this->arbitrage->decide(
        $enAppel->fresh() ?? $enAppel,
        agentArbitre(),
        ClaimDecision::Unresolved,
        'Réexamen : les pièces ne départagent pas.',
    );

    expect($second->status)->toBe(ClaimStatus::Decided);
});

it('refuse un appel hors délai', function (): void {
    $bien = bienConteste(partie());
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, $bien);
    $this->arbitrage->decide($dossier, agentArbitre(), ClaimDecision::KeepCurrent, 'Pièces insuffisantes.');

    $this->travel(16)->days();

    expect(fn () => $this->arbitrage->appeal($dossier->fresh() ?? $dossier, $reclamant))
        ->toThrow(DomainException::class);
});

it('laisse le dossier suivre son cours sans le moindre paiement', function (): void {
    // LES FRAIS SONT ANNONCÉS, JAMAIS OPPOSÉS (ST-0501). Leur rôle est de
    // décourager les dossiers de nuisance — contester la propriété d'autrui
    // doit coûter quelque chose — mais une victime démunie ne doit pas se voir
    // fermer son seul recours faute de 2000 francs.
    //
    // Ce test verrouille cette propriété : elle n'était affirmée que par un
    // commentaire, et rien n'empêchait qu'une porte de paiement s'ajoute un
    // jour sans que personne ne le remarque.
    $reclamant = partie();
    $bien = bienConteste(partie());

    // Ouverture, pièces, soumission : aucun paiement nulle part.
    $dossier = dossierRecevable($reclamant, $bien);

    expect($dossier->status)->not->toBe('draft');

    // L'instruction et la décision aboutissent tout autant.
    $this->arbitrage->decide(
        $dossier->fresh() ?? $dossier,
        agentArbitre(),
        ClaimDecision::TransferToClaimant,
        'Facture nominative concordante.',
    );

    expect(DB::table('payments')->count())->toBe(0);
});

it('annonce le montant des frais sans en faire une condition', function (): void {
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, bienConteste(partie()));

    $frais = $this->arbitrage->fee($dossier);

    expect($frais['amount_fcfa'])->toBe((int) config('preuve.claim_fee_fcfa'))
        // Non réglés, et le dossier a pourtant été reçu et instruit : c'est
        // exactement ce que « annoncés, jamais opposés » veut dire.
        ->and($frais['paid'])->toBeFalse();
});

it('rend les frais remboursables quand le réclamant avait raison', function (): void {
    // Le réclamant a eu raison de contester : il n'a pas à en supporter le
    // coût. Le droit au remboursement est constaté par la plateforme ; le
    // versement lui-même passe par l'opérateur de paiement.
    $reclamant = partie();
    $dossier = dossierRecevable($reclamant, bienConteste(partie()));

    expect($this->arbitrage->fee($dossier)['refundable'])->toBeFalse();

    $this->arbitrage->decide(
        $dossier->fresh() ?? $dossier,
        agentArbitre(),
        ClaimDecision::TransferToClaimant,
        'Facture nominative concordante.',
    );

    expect($this->arbitrage->fee($dossier->fresh() ?? $dossier)['refundable'])->toBeTrue();
});
