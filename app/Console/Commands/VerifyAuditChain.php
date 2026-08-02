<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditAnchorService;
use App\Services\AuditChain;
use Illuminate\Console\Command;

/**
 * Vérification complète de la chaîne d'audit.
 *
 * Deux contrôles, et il faut les DEUX :
 *
 * 1. La cohérence interne (AuditChain::verify) rejoue les empreintes. Elle
 *    attrape une retouche maladroite — une ligne modifiée en base — mais
 *    RIEN de plus : l'algorithme étant public et sans secret, une chaîne
 *    reconstruite de zéro passe ce contrôle sans difficulté.
 * 2. La confrontation aux ancrages externes compare les empreintes de tête
 *    publiées hors de la plateforme à ce que la base raconte aujourd'hui.
 *    C'est le seul contrôle qui détecte une reconstruction complète.
 */
final class VerifyAuditChain extends Command
{
    protected $signature = 'preuve:verify-audit-chain';

    protected $description = "Vérifie la chaîne d'audit et la confronte aux ancrages externes";

    public function handle(AuditChain $chain, AuditAnchorService $anchors): int
    {
        $interne = $chain->verify();

        if ($interne['valid']) {
            $this->components->info('Cohérence interne : chaîne intacte.');
        } else {
            $this->components->error(
                "Cohérence interne : RUPTURE à l'entrée #".((string) $interne['broken_at']).'.'
            );
        }

        $externe = $anchors->verifyAgainstAnchors();

        if ($externe['checked'] === 0) {
            $this->components->warn(
                "Aucun ancrage externe : la chaîne N'EST PAS opposable. Sa cohérence interne ne prouve rien, ".
                "l'algorithme étant public et reproductible par quiconque peut écrire en base."
            );

            return $interne['valid'] ? self::SUCCESS : self::FAILURE;
        }

        if ($externe['valid']) {
            $dernier = $anchors->lastSuccessful();

            $this->components->info(sprintf(
                'Ancrages externes : %d vérifié(s), tous concordants. Dernier ancrage le %s.',
                $externe['checked'],
                $dernier === null ? '—' : $dernier->created_at->format('d/m/Y H:i'),
            ));
        } else {
            $this->components->error('Ancrages externes : '.count($externe['breaches']).' rupture(s).');

            foreach ($externe['breaches'] as $rupture) {
                $this->components->twoColumnDetail(
                    'Ancrage #'.$rupture['anchor_id'].' du '.$rupture['anchored_at'],
                    $rupture['reason'],
                );
            }
        }

        return $interne['valid'] && $externe['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
