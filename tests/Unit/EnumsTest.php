<?php

declare(strict_types=1);

use App\Enums\LifeStatus;
use App\Enums\TrustLevel;

it('couvre les sept statuts de vie du PRD', function (): void {
    expect(LifeStatus::cases())->toHaveCount(7);
});

it('expose des libellés en langage courant, jamais les codes', function (): void {
    expect(LifeStatus::Stolen->label())->toBe('Volé déclaré')
        ->and(LifeStatus::Stolen->label())->not->toContain('V-VOL')
        ->and(LifeStatus::Disputed->label())->toBe('Litige en cours')
        ->and(LifeStatus::Rented->label())->toBe('En location')
        ->and(LifeStatus::Provisional->label())->toBe('Enregistrement récent')
        ->and(LifeStatus::Active->label())->toBe('Actif')
        ->and(LifeStatus::Transferring->label())->toBe('Transfert en cours')
        ->and(LifeStatus::EndOfLife->label())->toBe('Hors d\'usage');
});

it('associe une couleur du design system DJASSA à chaque statut', function (): void {
    expect(LifeStatus::Stolen->color())->toBe('#C62F21')
        ->and(LifeStatus::Disputed->color())->toBe('#C62F21')
        ->and(LifeStatus::Rented->color())->toBe('#C77700')
        ->and(LifeStatus::Transferring->color())->toBe('#1D4ED8')
        ->and(LifeStatus::Provisional->color())->toBe('#5C6470')
        ->and(LifeStatus::EndOfLife->color())->toBe('#5C6470');
});

it('identifie les statuts qui alertent publiquement l\'acheteur', function (): void {
    expect(LifeStatus::Stolen->isPublicWarning())->toBeTrue()
        ->and(LifeStatus::Disputed->isPublicWarning())->toBeTrue()
        ->and(LifeStatus::Rented->isPublicWarning())->toBeTrue()
        ->and(LifeStatus::Active->isPublicWarning())->toBeFalse();
});

it('expose les trois niveaux de confiance avec leurs libellés', function (): void {
    expect(TrustLevel::cases())->toHaveCount(3)
        ->and(TrustLevel::Declared->label())->toBe('Déclaré, non vérifié')
        ->and(TrustLevel::Documented->label())->toBe('Documenté')
        ->and(TrustLevel::Verified->label())->toBe('Vérifié')
        ->and(TrustLevel::Documented->color())->toBe('#1D4ED8')
        ->and(TrustLevel::Verified->color())->toBe('#1E8A4C')
        ->and(TrustLevel::Declared->color())->toBe('#5C6470');
});

it('conserve les codes du schéma comme valeurs stockées', function (): void {
    expect(LifeStatus::Active->value)->toBe('V-ACT')
        ->and(LifeStatus::Provisional->value)->toBe('V-PRV')
        ->and(TrustLevel::Declared->value)->toBe('F1');
});
