<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditAnchorService;
use App\Services\OpsReporter;
use Illuminate\Console\Command;

/**
 * Ancrage quotidien du hash de tête (ST-0106, systemPatterns §6).
 *
 * Sort en ÉCHEC quand aucun canal externe n'a abouti. C'est délibéré : le
 * planificateur remonte alors l'erreur, et l'exploitant apprend que la chaîne
 * n'est plus opposable. Une commande qui réussirait silencieusement en n'ayant
 * rien publié entretiendrait exactement l'illusion contre laquelle l'ancrage
 * existe.
 *
 * L'échec part aussi au destinataire d'exploitation. C'est LE défaut silencieux
 * de la plateforme : tout continue de fonctionner, aucun utilisateur ne voit
 * rien, et la chaîne cesse d'être opposable. Un code de sortie non nul dans un
 * journal de cron ne réveille personne.
 */
final class AnchorAuditHead extends Command
{
    protected $signature = 'preuve:anchor-audit-head';

    protected $description = "Publie l'empreinte de tête de la chaîne d'audit hors de la plateforme";

    public function __construct(private readonly OpsReporter $rapports)
    {
        parent::__construct();
    }

    public function handle(AuditAnchorService $anchors): int
    {
        $ancrage = $anchors->anchor();

        $publies = [];
        $echoues = [];
        $absents = [];

        foreach ($ancrage->channels as $canal => $resultat) {
            $statut = is_array($resultat) ? ($resultat['status'] ?? null) : null;

            match ($statut) {
                'published' => $publies[] = $canal,
                'failed' => $echoues[] = $canal,
                default => $absents[] = $canal,
            };
        }

        if ($ancrage->status === 'anchored') {
            $this->components->info(sprintf(
                'Ancrage #%d publié (%s) — tête #%s, %d entrée(s).',
                $ancrage->id,
                implode(', ', $publies),
                $ancrage->head_id === null ? '—' : (string) $ancrage->head_id,
                $ancrage->entry_count,
            ));

            if ($echoues !== []) {
                $this->components->warn(
                    'Canaux en échec : '.implode(', ', $echoues).'. L\'ancrage tient par les autres.'
                );
            }

            return self::SUCCESS;
        }

        $message = "AUCUN canal externe n'a abouti : la chaîne d'audit N'EST PAS opposable pour cette ".
            'période. '.
            ($absents !== [] ? 'Canaux non configurés : '.implode(', ', $absents).'. ' : '').
            ($echoues !== [] ? 'Canaux en échec : '.implode(', ', $echoues).'.' : '');

        $this->components->error($message);

        $this->rapports->alert(
            "Ancrage de la chaîne d'audit",
            $message.PHP_EOL.PHP_EOL.
            "Tant qu'aucun ancrage n'aboutit, la cohérence interne de la chaîne ne prouve rien : ".
            'l\'algorithme est public, et une chaîne reconstruite la passerait sans difficulté.',
            true,
        );

        return self::FAILURE;
    }
}
