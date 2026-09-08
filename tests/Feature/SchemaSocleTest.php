<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('crée la table users conforme au schéma de référence', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasColumns('users', [
            'phone', 'phone_verified_at', 'email', 'email_verified_at',
            'full_name', 'account_type', 'kyc_status', 'kyc_verified_at',
            'kyc_id_number_hash', 'kyc_ocr_payload', 'locale', 'status',
            'free_assets_quota',
        ]))->toBeTrue();
});

it('ne stocke aucun mot de passe pour les utilisateurs (OTP uniquement au MVP)', function (): void {
    expect(Schema::hasColumn('users', 'password'))->toBeFalse();
});

it('crée la table otp_codes avec les garde-fous anti-brute-force', function (): void {
    expect(Schema::hasColumns('otp_codes', [
        'destination', 'channel', 'purpose', 'code_hash',
        'attempts', 'max_attempts', 'expires_at', 'consumed_at', 'locked_until',
    ]))->toBeTrue();
});

it('ne stocke jamais un code OTP en clair', function (): void {
    expect(Schema::hasColumn('otp_codes', 'code'))->toBeFalse()
        ->and(Schema::hasColumn('otp_codes', 'code_hash'))->toBeTrue();
});

it('crée la table audit_log avec le chaînage SHA-256', function (): void {
    expect(Schema::hasColumns('audit_log', [
        'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'payload', 'record_hash', 'prev_hash', 'chain_hash',
    ]))->toBeTrue();
});

it('stocke created_at en DATETIME, jamais en TIMESTAMP', function (): void {
    // Un TIMESTAMP restitue sa représentation textuelle selon `time_zone` de
    // la session : la chaîne d'audit (§4.5 de la spec) hache cette
    // représentation, qui deviendrait donc dépendante du fuseau du client.
    // Cette assertion inspecte le type réel de la colonne en base — elle ne
    // dépend pas d'un migrate:fresh et détecte donc aussi une dérive de
    // schéma sur un environnement déjà migré (une revue a démontré qu'un
    // ALTER TABLE ... MODIFY created_at TIMESTAMP direct en base, suivi d'un
    // migrate normal, ne serait jamais rattrapé : « Nothing to migrate »).
    expect(Schema::getColumnType('audit_log', 'created_at'))->toBe('datetime');
});
