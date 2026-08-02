<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Models\Asset;
use App\Models\Notification;
use App\Models\User;
use App\Services\Delivery\PushTransport;
use App\Services\Delivery\SmsGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
    public function __construct(
        private readonly PushTransport $push,
        private readonly SmsGateway $sms,
    ) {}

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

        $notification = Notification::create([
            'user_id' => $destinataire->id,
            'type' => $type,
            'asset_id' => $bien?->id,
            'title' => $titre,
            'body' => $corps,
            'payload' => $payload === [] ? null : $payload,
            'channel' => $this->channelFor($type),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->deliver($destinataire, $notification);

        return $notification;
    }

    /**
     * Achemine hors de l'application (ST-1003, ST-1004).
     *
     * NE LÈVE JAMAIS. La notification in-app est déjà enregistrée : une
     * passerelle indisponible ne doit pas faire échouer l'action métier qui l'a
     * déclenchée — une déclaration de vol ne peut pas être refusée parce qu'un
     * agrégateur SMS répond mal.
     *
     * L'envoi est synchrone faute de file : il ajoute quelques centaines de
     * millisecondes aux actions critiques. À basculer sur la file
     * `notifications` quand Horizon sera en place.
     */
    private function deliver(User $destinataire, Notification $notification): void
    {
        $type = $notification->type;

        // Le push accompagne tout ce qui arrive au centre de notifications :
        // il ne coûte rien et ramène l'utilisateur dans l'application.
        if ($this->push->isConfigured()) {
            try {
                $this->push->send(
                    $destinataire,
                    $notification->title,
                    $notification->body,
                    ['type' => $type->value, 'asset_id' => (string) ($notification->asset_id ?? '')],
                );
            } catch (Throwable) {
                // Silence volontaire : voir la docstring.
            }
        }

        // Le SMS est réservé au critique. Il coûte, et un usage banalisé
        // apprendrait aux propriétaires à ignorer les alertes — exactement
        // celles qui comptent.
        if (! $type->isCritical()) {
            return;
        }

        if (! $this->sms->isConfigured()) {
            // Repli par courriel tant qu'aucune passerelle SMS n'est arbitrée.
            // Sans lui, une alerte critique — tentative d'enregistrement
            // frauduleux, transfert engagé — ne sortirait pas de l'application,
            // et son destinataire ne l'apprendrait qu'en l'ouvrant de lui-même.
            $this->deliverByMail($destinataire, $notification);

            return;
        }

        try {
            $this->sms->send($destinataire->phone, $this->smsText($notification));
            $this->traceCost($type->value, 'sms', 'sent', null);
        } catch (Throwable $e) {
            $this->traceCost($type->value, 'sms', 'failed', $e::class);
        }
    }

    /**
     * Repli courriel. NE LÈVE JAMAIS, pour la même raison que le reste de
     * `deliver()` : une déclaration de vol ne peut pas être refusée parce
     * qu'une passerelle de messagerie répond mal.
     *
     * Le journal de coût distingue ce canal du SMS : savoir combien d'alertes
     * critiques partent par un canal de repli est ce qui permet d'arbitrer
     * l'urgence d'un vrai fournisseur.
     */
    private function deliverByMail(User $destinataire, Notification $notification): void
    {
        $adresse = $destinataire->email;

        if (! is_string($adresse) || $adresse === '') {
            // Ni numéro joignable ni adresse : c'est un problème de données,
            // pas de passerelle, et le distinguer est ce qui permet de le voir.
            $this->traceCost($notification->type->value, 'mail', 'no_channel', null);

            return;
        }

        try {
            Mail::to($adresse)->send(new NotificationMail(
                $notification->title,
                $notification->asset?->public_ref,
            ));

            $this->traceCost($notification->type->value, 'mail', 'sent', null);
        } catch (Throwable $e) {
            $this->traceCost($notification->type->value, 'mail', 'failed', $e::class);
        }
    }

    /**
     * Texte du SMS : court, sans détail, et invitant à ouvrir l'application.
     *
     * Un SMS s'affiche sur un écran verrouillé, parfois sous les yeux d'un
     * tiers — ou du voleur, si le téléphone a été pris avec le bien. Le détail
     * reste derrière l'authentification.
     */
    private function smsText(Notification $notification): string
    {
        return 'PREUVE : '.$notification->title.'. Ouvrez l\'application pour le détail.';
    }

    /**
     * Journal de coût (ST-1004) : le TYPE d'alerte, le canal et l'issue, jamais
     * le destinataire. Savoir combien de SMS partent et pour quels motifs suffit à
     * piloter la dépense ; un journal nominatif ajouterait une donnée
     * personnelle de plus à protéger et à purger.
     */
    private function traceCost(string $type, string $canal, string $statut, ?string $erreur): void
    {
        DB::table('sms_deliveries')->insert([
            'notification_type' => $type,
            // Canal et statut sont orthogonaux : les confondre interdirait de
            // demander « combien d'alertes ont échoué, tous canaux confondus ».
            'channel' => $canal,
            'status' => $statut,
            'failure_class' => $erreur,
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
