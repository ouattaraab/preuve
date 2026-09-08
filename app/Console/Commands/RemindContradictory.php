<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ClaimStatus;
use App\Enums\NotificationType;
use App\Models\Asset;
use App\Models\Claim;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Relances du contradictoire et clôture du délai (ST-0504).
 *
 * Le mis en cause dispose de 15 jours pour produire ses justificatifs. C'est
 * le seul moment où il peut se défendre, et son silence a une conséquence
 * lourde : le dossier sera instruit sur les seules pièces disponibles,
 * c'est-à-dire celles du réclamant. Une notification unique au premier jour
 * serait insuffisante — elle arrive au milieu d'autres, elle se perd, et la
 * personne qui perd son bien par inattention n'aura pas eu tort de bonne foi.
 *
 * D'où deux rappels, à J+7 et J+13, dont le second annonce l'échéance à deux
 * jours. Le compteur de relances évite qu'un passage horaire ne répète le même
 * message pendant huit jours : une alerte répétée finit ignorée, et c'est
 * précisément celle-là qu'il ne faut pas manquer.
 *
 * AU TERME, LE SILENCE NE FERME PAS LE DOSSIER : il le fait passer en
 * instruction. Le bien reste gelé, et l'agent tranche sur pièces. Refermer le
 * dossier faute de réponse reviendrait à donner raison au réclamant sans
 * examen — l'inverse exact de ce que le contradictoire protège.
 */
final class RemindContradictory extends Command
{
    protected $signature = 'preuve:remind-contradictory {--limit=200 : Nombre maximal de dossiers par passage}';

    protected $description = 'Relance le mis en cause et clôt les délais contradictoires échus';

    /** Jours écoulés depuis l'ouverture auxquels partent les rappels. */
    private const REMINDER_DAYS = [7, 13];

    private const CONTRADICTORY_DAYS = 15;

    public function handle(NotificationService $notifications): int
    {
        $limite = max(1, (int) $this->option('limit'));

        $dossiers = Claim::query()
            ->where('status', ClaimStatus::Contradictory->value)
            ->whereNotNull('respondent_deadline')
            ->orderBy('respondent_deadline')
            ->limit($limite)
            ->get();

        $relances = 0;
        $clos = 0;

        foreach ($dossiers as $dossier) {
            try {
                if ($dossier->respondent_deadline?->isPast() === true) {
                    $this->closeDeadline($notifications, $dossier);
                    $clos++;

                    continue;
                }

                if ($this->remindIfDue($notifications, $dossier)) {
                    $relances++;
                }
            } catch (Throwable $e) {
                $this->components->warn(
                    "Dossier #{$dossier->id} non traité (".$e::class.') : repris au passage suivant.'
                );
            }
        }

        $this->components->info("{$relances} relance(s) envoyée(s), {$clos} délai(s) clos.");

        if ($dossiers->count() === $limite) {
            $this->components->warn("Limite de {$limite} atteinte : d'autres dossiers restent à traiter.");
        }

        return self::SUCCESS;
    }

    /** @return bool vrai si une relance a été envoyée */
    private function remindIfDue(NotificationService $notifications, Claim $dossier): bool
    {
        $deadline = $dossier->respondent_deadline;

        if ($deadline === null) {
            return false;
        }

        // Jours écoulés comptés depuis l'ouverture reconstituée, et non par
        // soustraction du reste : une différence flottante de 8,999 jour
        // s'arrondirait à 8, ce qui avancerait le rappel d'une journée entière.
        $ouverture = $deadline->copy()->subDays(self::CONTRADICTORY_DAYS);
        $ecoules = (int) floor($ouverture->diffInDays(now(), true));
        $dejaEnvoyees = $dossier->reminders_sent;

        $aEnvoyer = 0;

        foreach (self::REMINDER_DAYS as $index => $jour) {
            if ($ecoules >= $jour) {
                $aEnvoyer = $index + 1;
            }
        }

        if ($aEnvoyer <= $dejaEnvoyees) {
            return false;
        }

        $bien = $dossier->asset;

        if (! $bien instanceof Asset) {
            return false;
        }

        $detenteur = $bien->owner;

        if (! $detenteur instanceof User) {
            return false;
        }

        $restants = max(0, (int) ceil(now()->diffInDays($deadline, true)));

        $notifications->notify(
            $detenteur,
            NotificationType::ClaimOpened,
            $restants <= 2
                ? 'Dernier rappel : il vous reste '.$restants.' jour(s)'
                : 'Rappel : une réclamation vise votre bien',
            'Vous n\'avez pas encore produit vos justificatifs de propriété. Passé le délai, le dossier sera '.
            'instruit sur les seules pièces disponibles — celles de la partie qui vous conteste. '.
            'Il vous reste '.$restants.' jour(s).',
            $bien,
            ['claim_id' => $dossier->id, 'days_left' => $restants],
        );

        $dossier->forceFill([
            'reminders_sent' => $aEnvoyer,
            'last_reminder_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Le délai est écoulé : le dossier part en instruction. Le bien RESTE
     * gelé — refermer faute de réponse donnerait raison au réclamant sans
     * examen.
     */
    private function closeDeadline(NotificationService $notifications, Claim $dossier): void
    {
        $dossier->forceFill(['status' => ClaimStatus::UnderReview])->save();

        $bien = $dossier->asset;

        if ($bien instanceof Asset && $bien->owner instanceof User) {
            $notifications->notify(
                $bien->owner,
                NotificationType::ClaimOpened,
                'Le délai de réponse est écoulé',
                'Le dossier passe en instruction. Il sera examiné sur les pièces disponibles ; votre bien '.
                'reste gelé jusqu\'à la décision.',
                $bien,
                ['claim_id' => $dossier->id],
            );
        }

        $notifications->notify(
            $dossier->claimantOrFail(),
            NotificationType::ClaimOpened,
            'Votre réclamation passe en instruction',
            'Le délai laissé à la partie adverse est écoulé. Un agent examine désormais le dossier.',
            null,
            ['claim_id' => $dossier->id],
        );
    }
}
