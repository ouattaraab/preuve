<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Asset;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Dépôt des notifications au centre in-app et routage vers les canaux
 * d'interruption (EP-10).
 *
 * Deux règles inviolables gouvernent ce service.
 *
 * L'ANONYMAT EST SYMÉTRIQUE (règle métier absolue n° 4). Le propriétaire
 * apprend QUE son bien est consulté, jamais PAR QUI — même quand le consultant
 * a payé un rapport. L'inverse transformerait la plateforme en outil de traque :
 * il suffirait d'enregistrer un bien pour savoir qui s'y intéresse. Aucune
 * méthode de cette classe ne reçoit l'identité d'un consultant, ce qui rend la
 * fuite structurellement impossible plutôt que seulement interdite.
 *
 * LES CONSULTATIONS SONT AGRÉGÉES, jamais unitaires (systemPatterns §5). Une
 * notification par consultation apprendrait au propriétaire le rythme exact des
 * visites — donc, par recoupement avec ce qu'il sait par ailleurs, qui regarde
 * et quand. Elle noierait aussi le signal utile sous le bruit.
 *
 * Les préférences (ST-0107) permettent de se taire sur les types facultatifs.
 * Les types critiques n'y sont pas soumis : couper l'alerte d'une tentative
 * d'enregistrement frauduleux reviendrait à laisser un propriétaire perdre son
 * bien sans jamais l'apprendre.
 */
final class NotificationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return Notification|null null si l'utilisateur a demandé le silence sur
     *                           ce type
     */
    public function notify(
        User $destinataire,
        NotificationType $type,
        string $titre,
        string $corps,
        ?Asset $bien = null,
        array $payload = [],
    ): ?Notification {
        if (! $this->accepts($destinataire, $type)) {
            return null;
        }

        return Notification::create([
            'user_id' => $destinataire->id,
            'type' => $type,
            'asset_id' => $bien?->id,
            'title' => $titre,
            'body' => $corps,
            'payload' => $payload === [] ? null : $payload,
            'channel' => $this->channelFor($type),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Notification agrégée de consultation (ST-1002).
     *
     * Ne reçoit qu'un COMPTEUR et une période : ni identifiants de consultants,
     * ni empreintes d'adresses, ni horodatages unitaires. C'est ce qui garantit
     * que le propriétaire ressent la protection active sans jamais pouvoir
     * remonter à quiconque.
     */
    public function notifyLookupActivity(Asset $bien, int $consultations, Carbon $debut, Carbon $fin): ?Notification
    {
        if ($consultations < 1) {
            return null;
        }

        // Un bien archivé a changé de mains : son ancien détenteur n'a plus à
        // savoir qui le consulte.
        if ($bien->active_flag === null) {
            return null;
        }

        $proprietaire = $bien->owner;

        if (! $proprietaire instanceof User) {
            return null;
        }

        $libelle = $consultations === 1
            ? 'Votre bien a été consulté 1 fois.'
            : "Votre bien a été consulté {$consultations} fois.";

        return $this->notify(
            $proprietaire,
            NotificationType::AssetLookup,
            'Votre bien a été consulté',
            $libelle.' Les consultations sont anonymes : PREUVE ne vous dira jamais qui consulte.',
            $bien,
            [
                'count' => $consultations,
                'from' => $debut->toIso8601String(),
                'to' => $fin->toIso8601String(),
            ],
        );
    }

    /**
     * Enregistre les préférences et renvoie celles qui ont été retenues.
     *
     * Les demandes portant sur un type critique ou inexistant sont ignorées
     * plutôt que rejetées : l'utilisateur garde une interface qui répond, et
     * la réponse lui montre exactement ce qui a été pris en compte.
     *
     * @param  array<string, mixed>  $demandes
     * @return array<string, bool>
     */
    public function updatePreferences(User $utilisateur, array $demandes): array
    {
        $retenues = $this->preferencesOf($utilisateur);

        foreach ($demandes as $cle => $valeur) {
            $type = NotificationType::tryFrom((string) $cle);

            if ($type === null || ! $type->isOptional()) {
                continue;
            }

            $retenues[$type->value] = (bool) $valeur;
        }

        $utilisateur->forceFill(['notification_preferences' => $retenues])->save();

        return $retenues;
    }

    /**
     * Préférences enregistrées. Un compte neuf n'en a aucune, et reçoit donc
     * tout : un propriétaire ne doit pas avoir à activer les alertes sur ses
     * propres biens pour être protégé.
     *
     * @return array<string, bool>
     */
    public function preferencesOf(User $utilisateur): array
    {
        $brutes = $utilisateur->notification_preferences;
        $preferences = [];

        if (is_array($brutes)) {
            foreach ($brutes as $cle => $valeur) {
                $type = NotificationType::tryFrom((string) $cle);

                if ($type !== null && $type->isOptional()) {
                    $preferences[$type->value] = (bool) $valeur;
                }
            }
        }

        return $preferences;
    }

    private function accepts(User $destinataire, NotificationType $type): bool
    {
        if (! $type->isOptional()) {
            return true;
        }

        return $this->preferencesOf($destinataire)[$type->value] ?? true;
    }

    /**
     * Canal d'acheminement. Le SMS est réservé au critique : il coûte, et un
     * usage banalisé apprendrait aux propriétaires à ignorer les alertes —
     * exactement celles qui comptent.
     *
     * Le transport réel (FCM, SMS) reste à brancher : la colonne dit par quel
     * canal la notification DOIT partir, le centre in-app la porte déjà.
     */
    private function channelFor(NotificationType $type): string
    {
        return $type->isCritical() ? 'sms' : 'inapp';
    }
}
