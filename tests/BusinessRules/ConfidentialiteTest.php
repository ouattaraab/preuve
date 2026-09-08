<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\LegalContactController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Page de confidentialité et point de contact (Loi 2013-450).
 *
 * ELLE DÉCRIT CE QUE LE CODE FAIT, pas ce qu'il serait souhaitable qu'il fasse.
 * Une politique qui promettrait au-delà du code serait une déclaration
 * mensongère de plus — et c'est exactement le document qu'une autorité de
 * protection des données confronterait au comportement réel.
 *
 * TANT QU'AUCUNE ADRESSE N'EST RÉGLÉE, LA PAGE LE DIT. En afficher une inventée
 * serait pire que de n'en afficher aucune : la personne qui écrit pour faire
 * effacer ses données croirait avoir saisi le responsable, et le silence
 * passerait pour un refus.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['audit_log', 'app_settings', 'personal_access_tokens', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');

    app(SettingsRepository::class)->fresh();
});

function administrateurLegal(): User
{
    $compte = User::create(['phone' => '+2250700001600']);
    $compte->forceFill(['role' => UserRole::Admin, 'full_name' => 'Awa Traoré'])->save();
    Sanctum::actingAs($compte);

    return $compte;
}

it('sert la page de confidentialité sans compte et sans cookie', function (): void {
    $reponse = $this->get('/confidentialite')->assertOk();

    // La lire ne doit rien coûter en traces : c'est la page que consulte
    // précisément celui qui s'inquiète de ce qu'on garde de lui.
    expect($reponse->headers->getCookies())->toBeEmpty()
        ->and($reponse->headers->get('X-Robots-Tag'))->toBe('index, follow');
});

it('annonce le guichet en cours d\'ouverture plutôt qu\'une adresse muette', function (): void {
    $page = $this->get('/confidentialite')->assertOk();

    // Une adresse qui ne répondrait pas ferait passer le silence pour un refus.
    $page->assertSee('en cours d\'ouverture', false)
        ->assertDontSee('mailto:');
});

it('publie l\'adresse réglée par l\'administrateur', function (): void {
    administrateurLegal();

    $this->putJson('/api/v1/admin/legal-contact', ['contact' => 'donnees@preuve.click'])->assertOk();

    $this->get('/confidentialite')->assertOk()->assertSee('donnees@preuve.click');
});

it('journalise le changement sans écrire l\'adresse dans la chaîne', function (): void {
    administrateurLegal();

    $this->putJson('/api/v1/admin/legal-contact', ['contact' => 'donnees@preuve.click'])->assertOk();

    $entree = AuditLog::where('action', 'admin.legal_contact_changed')->firstOrFail();

    // Détourner le point de contact des droits est une manière discrète
    // d'empêcher leur exercice : le savoir suffit à le contester. L'adresse
    // elle-même n'a rien à faire dans une chaîne inaltérable.
    expect($entree->payload['now_set'])->toBeTrue()
        ->and(json_encode($entree->payload))->not->toContain('donnees@preuve.click');
});

it('réserve le réglage aux administrateurs', function (): void {
    $agent = User::create(['phone' => '+2250700001601']);
    $agent->forceFill(['role' => UserRole::Agent])->save();
    Sanctum::actingAs($agent);

    $this->getJson('/api/v1/admin/legal-contact')->assertStatus(403);
    $this->putJson('/api/v1/admin/legal-contact', ['contact' => 'pirate@ailleurs.test'])->assertStatus(403);
});

it('distingue effacer de ne pas toucher', function (): void {
    administrateurLegal();
    $this->putJson('/api/v1/admin/legal-contact', ['contact' => 'donnees@preuve.click'])->assertOk();

    // Champ absent : aucun changement. Champ vide : effacement demandé.
    $this->putJson('/api/v1/admin/legal-contact', [])->assertStatus(422);
    expect(app(SettingsRepository::class)->fresh()->get(LegalContactController::SETTING))
        ->toBe('donnees@preuve.click');

    $this->putJson('/api/v1/admin/legal-contact', ['contact' => null])->assertOk();
    expect(app(SettingsRepository::class)->fresh()->get(LegalContactController::SETTING))->toBeNull();
});

it('décrit le hachage salé quotidien, qui est ce que le code fait', function (): void {
    $corps = (string) $this->get('/confidentialite')->assertOk()->getContent();

    // Chaque affirmation de cette page correspond à un mécanisme vérifiable
    // dans le dépôt. Celle-ci porte sur la protection des consultations.
    expect($corps)->toContain('jamais l\'adresse elle-même')
        ->toContain('douze mois')
        // Le numéro de pièce n'est pas restituable, y compris sur réquisition :
        // la page le dit parce que le code le fait.
        ->toContain('pas restituable');
});
