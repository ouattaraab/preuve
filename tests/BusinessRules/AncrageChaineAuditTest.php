<?php

declare(strict_types=1);

use App\Enums\ActorType;
use App\Mail\AuditAnchorMail;
use App\Models\AuditAnchor;
use App\Services\Audit\MailAnchorChannel;
use App\Services\Audit\StorageAnchorChannel;
use App\Services\AuditAnchorService;
use App\Services\AuditChain;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * ST-0106, systemPatterns §6 : sans ancrage externe, la chaîne d'audit N'EST
 * PAS opposable. Son algorithme est public et sans secret : quiconque a le
 * droit INSERT peut reconstruire une chaîne cohérente de bout en bout.
 *
 * N'utilise pas RefreshDatabase : AuditChain refuse toute transaction
 * englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('audit_anchors')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerAncrages();
    Mail::fake();
    Storage::fake('anchors');

    $this->settings = app(SettingsRepository::class)->fresh();
});
afterEach(fn () => nettoyerAncrages());

function nettoyerAncrages(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_anchors', 'audit_log', 'app_settings'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

function configurerAncrageMail(): void
{
    test()->settings->set(MailAnchorChannel::RECIPIENT_SETTING, 'archives@preuve.ci');
}

function configurerAncrageStockage(): void
{
    test()->settings->set(StorageAnchorChannel::DISK_SETTING, 'anchors');
}

function ecrireAudit(string $action = 'test.action'): void
{
    app(AuditChain::class)->append(ActorType::System, null, $action, 'asset', 1, ['x' => 1]);
}

it('publie l\'empreinte de tête par courrier', function (): void {
    configurerAncrageMail();
    ecrireAudit();

    $ancrage = app(AuditAnchorService::class)->anchor();

    expect($ancrage->status)->toBe('anchored')
        ->and($ancrage->entry_count)->toBe(1);

    Mail::assertSent(AuditAnchorMail::class, function (AuditAnchorMail $mail): bool {
        return str_contains($mail->document->toText(), $mail->document->headChainHash);
    });
});

it('dépose l\'empreinte sur le stockage séparé', function (): void {
    configurerAncrageStockage();
    ecrireAudit();

    app(AuditAnchorService::class)->anchor();

    expect(Storage::disk('anchors')->allFiles())->toHaveCount(1);
});

it('consigne un ÉCHEC quand aucun canal n\'est configuré', function (): void {
    // Se croire protégé sans l'être est pire que savoir qu'on ne l'est pas :
    // c'est ce qui ferait affirmer une opposabilité inexistante.
    ecrireAudit();

    $ancrage = app(AuditAnchorService::class)->anchor();

    expect($ancrage->status)->toBe('failed')
        ->and($ancrage->channels['mail']['status'] ?? null)->toBe('not_configured');
});

it('tient dès qu\'un seul canal aboutit', function (): void {
    configurerAncrageMail();
    ecrireAudit();

    $ancrage = app(AuditAnchorService::class)->anchor();

    expect($ancrage->status)->toBe('anchored')
        ->and($ancrage->channels['storage']['status'] ?? null)->toBe('not_configured');
});

it('ancre même quand rien n\'a été ajouté', function (): void {
    // Un ancrage identique au précédent atteste qu'aucune entrée n'a été
    // insérée entre les deux dates : une chaîne reconstruite plus courte se
    // trahirait par un compteur en baisse.
    configurerAncrageMail();

    $premier = app(AuditAnchorService::class)->anchor();
    $second = app(AuditAnchorService::class)->anchor();

    expect($premier->head_chain_hash)->toBe($second->head_chain_hash)
        ->and(AuditAnchor::count())->toBe(2);
});

it('ancre une chaîne encore vide sans se plaindre', function (): void {
    configurerAncrageMail();

    $ancrage = app(AuditAnchorService::class)->anchor();

    expect($ancrage->status)->toBe('anchored')
        ->and($ancrage->head_id)->toBeNull()
        ->and($ancrage->entry_count)->toBe(0);
});

it('confirme la concordance tant que rien n\'est réécrit', function (): void {
    configurerAncrageMail();
    ecrireAudit();
    app(AuditAnchorService::class)->anchor();
    ecrireAudit();

    $verification = app(AuditAnchorService::class)->verifyAgainstAnchors();

    expect($verification['valid'])->toBeTrue()
        ->and($verification['checked'])->toBe(1);
});

it('démasque une chaîne reconstruite après l\'ancrage', function (): void {
    // LE test qui justifie tout le dispositif. La chaîne reconstruite est
    // parfaitement cohérente avec elle-même : AuditChain::verify() la valide.
    // Seule la confrontation à l'empreinte publiée dehors la démasque.
    configurerAncrageMail();
    ecrireAudit('action.genante');
    ecrireAudit('action.suivante');

    app(AuditAnchorService::class)->anchor();

    // Un attaquant ayant le droit INSERT vide la table et la reconstruit sans
    // l'action gênante. Les déclencheurs interdisent UPDATE et DELETE, mais
    // pas TRUNCATE : c'est précisément le trou que l'ancrage couvre.
    DB::statement('TRUNCATE TABLE audit_log');
    ecrireAudit('action.suivante');
    ecrireAudit('action.inventee');

    $interne = app(AuditChain::class)->verify();
    $externe = app(AuditAnchorService::class)->verifyAgainstAnchors();

    expect($interne['valid'])->toBeTrue()
        ->and($externe['valid'])->toBeFalse()
        ->and($externe['breaches'][0]['reason'])->toContain('réécrite');
});

it('démasque la disparition d\'entrées ancrées', function (): void {
    configurerAncrageMail();
    ecrireAudit();
    ecrireAudit();
    ecrireAudit();
    app(AuditAnchorService::class)->anchor();

    DB::statement('TRUNCATE TABLE audit_log');

    $externe = app(AuditAnchorService::class)->verifyAgainstAnchors();

    expect($externe['valid'])->toBeFalse()
        ->and($externe['breaches'][0]['reason'])->toContain("n'existe plus");
});

it('ne tient pas compte des ancrages en échec', function (): void {
    // Un ancrage qui n'a jamais quitté la plateforme ne prouve rien : le
    // confronter donnerait une fausse assurance.
    ecrireAudit();
    app(AuditAnchorService::class)->anchor();

    expect(AuditAnchor::sole()->status)->toBe('failed')
        ->and(app(AuditAnchorService::class)->verifyAgainstAnchors()['checked'])->toBe(0);
});

it('interdit toute réécriture du registre d\'ancrage', function (): void {
    configurerAncrageMail();
    $ancrage = app(AuditAnchorService::class)->anchor();

    expect(fn () => $ancrage->update(['head_chain_hash' => str_repeat('f', 64)]))
        ->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => AuditAnchor::query()->where('id', $ancrage->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('n\'écrit aucune donnée personnelle dans le constat', function (): void {
    // Le constat part chez un tiers et y reste indéfiniment : il ne doit
    // contenir que des empreintes, un compteur et une date (Loi 2013-450).
    configurerAncrageStockage();
    app(AuditChain::class)->append(
        ActorType::User,
        42,
        'auth.session_opened',
        'user',
        42,
        ['phone' => '+2250700000001'],
    );

    app(AuditAnchorService::class)->anchor();

    $contenu = Storage::disk('anchors')->get(Storage::disk('anchors')->allFiles()[0]);

    expect($contenu)->not->toContain('+2250700000001')
        ->and($contenu)->not->toContain('auth.session_opened')
        ->and($contenu)->toContain('aucune donnée personnelle');
});

it('sort en échec quand la chaîne n\'a pu être ancrée nulle part', function (): void {
    // Le planificateur remonte alors l'erreur : l'exploitant apprend que la
    // chaîne n'est plus opposable.
    ecrireAudit();

    $this->artisan('preuve:anchor-audit-head')->assertFailed();
});

it('réussit dès qu\'un canal a publié', function (): void {
    configurerAncrageMail();
    ecrireAudit();

    $this->artisan('preuve:anchor-audit-head')->assertSuccessful();
});

it('avertit que la chaîne n\'est pas opposable en l\'absence d\'ancrage', function (): void {
    ecrireAudit();

    $this->artisan('preuve:verify-audit-chain')
        ->expectsOutputToContain("N'EST PAS opposable")
        ->assertSuccessful();
});

it('signale la rupture à la vérification complète', function (): void {
    configurerAncrageMail();
    ecrireAudit();
    app(AuditAnchorService::class)->anchor();

    // Action différente de l'originale : une reconstruction rigoureusement
    // identique ne serait pas une falsification, et n'a donc rien à signaler.
    DB::statement('TRUNCATE TABLE audit_log');
    ecrireAudit('action.reconstruite');

    $this->artisan('preuve:verify-audit-chain')->assertFailed();
});
