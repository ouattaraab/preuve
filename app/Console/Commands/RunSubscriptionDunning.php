<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Relances d'abonnement et suspension douce (ST-0805).
 *
 * Trois rappels avant fermeture de l'ajout de véhicules : une facture peut se
 * perdre, un responsable changer, un virement traîner. Suspendre au premier
 * jour de retard ferait perdre un client sur un malentendu administratif.
 *
 * Aucune relance ne retire la protection des véhicules déjà enregistrés — c'est
 * le point sur lequel ce parcours ne transige jamais.
 */
final class RunSubscriptionDunning extends Command
{
    protected $signature = 'preuve:subscription-dunning {--limit=200 : Flottes traitées par passage}';

    protected $description = 'Relance les abonnements en retard et bascule en lecture seule au terme';

    public function handle(SubscriptionService $abonnements): int
    {
        $limite = max(1, (int) $this->option('limit'));

        $flottes = Company::query()
            ->whereNotNull('subscription_due_at')
            ->where('subscription_due_at', '<=', now())
            ->whereIn('subscription_status', [
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->orderBy('subscription_due_at')
            ->limit($limite)
            ->get();

        $traitees = 0;

        foreach ($flottes as $societe) {
            try {
                if ($abonnements->advance($societe)) {
                    $traitees++;
                }
            } catch (Throwable $e) {
                $this->components->warn(
                    "Flotte #{$societe->id} non traitée (".$e::class.') : reprise au passage suivant.'
                );
            }
        }

        $this->components->info("{$traitees} flotte(s) relancée(s) ou suspendue(s).");

        return self::SUCCESS;
    }
}
