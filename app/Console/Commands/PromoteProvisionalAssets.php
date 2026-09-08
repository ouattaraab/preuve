<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LifeStatus;
use App\Enums\NotificationType;
use App\Enums\TriggerType;
use App\Models\Asset;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\StatusTransitionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Clôt la fenêtre de contestation des enregistrements récents (ST-0402).
 *
 * Un bien enregistré naît « Enregistrement récent » pour 30 jours : le temps
 * qu'un propriétaire dépossédé se manifeste. Passé ce terme sans réclamation
 * recevable, il devient « Actif ».
 *
 * LA CONDITION « SANS RÉCLAMATION RECEVABLE » N'EST PAS EXPRIMÉE ICI, et c'est
 * volontaire : la recevabilité d'une réclamation fait passer le bien en
 * « Litige en cours » (matrice, systemPatterns §1), donc hors du périmètre de
 * ce job. Ajouter un contrôle sur la table des réclamations dupliquerait la
 * règle à deux endroits, avec le risque qu'ils divergent. Les tests verrouillent
 * ce raisonnement pour qu'il reste vrai quand cette table existera.
 *
 * Un bien promu à tort n'est d'ailleurs pas un piège définitif : la matrice
 * autorise « Actif » → « Litige en cours », si bien qu'une réclamation jugée
 * recevable après le terme gèle le bien de la même façon.
 *
 * Le passage est BORNÉ. Chaque transition prend le verrou nommé de la chaîne
 * d'audit, dont le plafond mesuré est d'une quinzaine d'actions simultanées :
 * un passage sans limite, sur un parc important, ferait échouer les actions des
 * autres utilisateurs pendant tout son déroulement.
 */
final class PromoteProvisionalAssets extends Command
{
    protected $signature = 'preuve:promote-provisional {--limit=200 : Nombre maximal de bascules par passage}';

    protected $description = 'Bascule en Actif les enregistrements récents dont la fenêtre de contestation est close';

    public function handle(StatusTransitionService $transitions, NotificationService $notifications): int
    {
        $limite = max(1, (int) $this->option('limit'));

        $echus = Asset::query()
            ->where('life_status', LifeStatus::Provisional->value)
            ->whereNotNull('active_flag')
            // Sans terme renseigné, il n'y a pas de fenêtre à clore : mieux
            // vaut laisser le bien en l'état qu'inventer une date.
            ->whereNotNull('provisional_until')
            ->where('provisional_until', '<=', now())
            ->orderBy('provisional_until')
            ->limit($limite)
            ->get();

        $promus = 0;
        $echecs = 0;

        foreach ($echus as $bien) {
            try {
                $transitions->transitionTo(
                    $bien,
                    LifeStatus::Active,
                    TriggerType::System,
                    null,
                    'Fin de la fenêtre de contestation de 30 jours',
                );

                $this->announce($notifications, $bien);
                $promus++;
            } catch (Throwable $e) {
                // Un bien qui échoue ne retient pas les autres : ils ont tous
                // atteint le terme de leur fenêtre et n'ont pas à attendre le
                // passage suivant.
                $echecs++;
                $this->components->warn(
                    "Bien #{$bien->id} non basculé (".$e::class.') : il sera repris au passage suivant.'
                );
            }
        }

        $this->components->info("{$promus} enregistrement(s) basculé(s) en Actif.");

        if ($echus->count() === $limite) {
            // Dire ce qui a été laissé de côté : un passage silencieusement
            // tronqué se lit comme « tout a été traité ».
            $this->components->warn(
                "Limite de {$limite} atteinte : d'autres biens échus restent à basculer au passage suivant."
            );
        }

        if ($echecs > 0) {
            $this->components->warn("{$echecs} bien(s) en échec.");
        }

        return self::SUCCESS;
    }

    /**
     * Le passage en « Actif » lève la mention de contestation affichée aux
     * acheteurs : le propriétaire a intérêt à le savoir. Type facultatif, donc
     * soumis aux préférences (ST-0107).
     */
    private function announce(NotificationService $notifications, Asset $bien): void
    {
        $proprietaire = $bien->owner;

        if (! $proprietaire instanceof User) {
            return;
        }

        $notifications->notify(
            $proprietaire,
            NotificationType::StatusChange,
            'Votre bien est désormais Actif',
            'La fenêtre de contestation de 30 jours est close : votre bien passe de « Enregistrement récent » '.
            'à « Actif ». Les acheteurs ne verront plus la mention de contestation en cours.',
            $bien,
            ['from' => LifeStatus::Provisional->value, 'to' => LifeStatus::Active->value],
        );
    }
}
