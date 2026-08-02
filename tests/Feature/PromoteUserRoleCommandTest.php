<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N'utilise pas RefreshDatabase : la commande journalise dans la chaîne
 * d'audit, qui refuse une transaction englobante.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('app_settings')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    nettoyerRoles();
});
afterEach(fn () => nettoyerRoles());

function nettoyerRoles(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');

    foreach (['audit_log', 'users'] as $table) {
        DB::statement("TRUNCATE TABLE {$table}");
    }

    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

it('attribue le rôle demandé à un compte existant', function (): void {
    $utilisateur = User::create(['phone' => '+2250700000001']);

    $this->artisan('preuve:role', ['phone' => '+2250700000001', 'role' => 'admin'])
        ->assertSuccessful();

    expect($utilisateur->fresh()?->role)->toBe(UserRole::Admin);
});

it('crée tout compte ordinaire, jamais administrateur', function (): void {
    // Le parcours public d'inscription ne doit ouvrir aucun accès au
    // back-office : c'est la valeur par défaut de la colonne qui le garantit.
    expect(User::create(['phone' => '+2250700000002'])->fresh()?->role)->toBe(UserRole::User);
});

it('journalise le changement de rôle sans écrire le numéro', function (): void {
    User::create(['phone' => '+2250700000001']);

    $this->artisan('preuve:role', ['phone' => '+2250700000001', 'role' => 'admin'])->assertSuccessful();

    $entree = AuditLog::where('action', 'admin.role_changed')->sole();

    expect($entree->payload)->toMatchArray(['from' => 'user', 'to' => 'admin'])
        ->and(json_encode($entree->payload))->not->toContain('2250700000001');
});

it('refuse un rôle inconnu', function (): void {
    User::create(['phone' => '+2250700000001']);

    $this->artisan('preuve:role', ['phone' => '+2250700000001', 'role' => 'superadmin'])
        ->assertFailed();

    expect(User::sole()->role)->toBe(UserRole::User);
});

it('refuse un compte inexistant', function (): void {
    $this->artisan('preuve:role', ['phone' => '+2250799999999', 'role' => 'admin'])
        ->assertFailed();
});

it('permet de retirer un rôle attribué par erreur', function (): void {
    User::create(['phone' => '+2250700000001']);

    $this->artisan('preuve:role', ['phone' => '+2250700000001', 'role' => 'admin'])->assertSuccessful();
    $this->artisan('preuve:role', ['phone' => '+2250700000001', 'role' => 'user'])->assertSuccessful();

    expect(User::sole()->role)->toBe(UserRole::User)
        ->and(AuditLog::where('action', 'admin.role_changed')->count())->toBe(2);
});
