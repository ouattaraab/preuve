<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use App\Models\WatchAlert;
use DomainException;

/**
 * Veille sur un identifiant (ST-0403).
 *
 * QUI PEUT VEILLER SUR QUOI est la question centrale de ce service, et elle
 * n'est pas anodine : une veille libre permettrait de surveiller le bien
 * d'autrui — de savoir quand il est consulté, donc quand il est mis en vente,
 * donc quand son détenteur s'absente. Ce serait retourner contre les
 * utilisateurs le dispositif censé les protéger, et violer l'anonymat
 * symétrique (règle métier absolue n° 4).
 *
 * La veille est donc réservée à qui EST ou A ÉTÉ détenteur d'un enregistrement
 * portant cet identifiant. Le second cas n'est pas une largesse : une victime
 * de vol dont le bien a été réenregistré par un tiers n'est plus détentrice
 * d'aucune ligne active, et c'est précisément elle qui a le plus besoin d'être
 * alertée quand « son » identifiant est consulté.
 *
 * Un identifiant inconnu de la plateforme n'est veillable par personne : sans
 * cette borne, il suffirait de veiller sur un identifiant au hasard pour être
 * prévenu du jour où quelqu'un l'enregistre.
 */
final class WatchAlertService
{
    public function __construct(private readonly IdentifierNormalizer $normalizer) {}

    /**
     * Active une veille, ou réactive celle qui existe déjà.
     *
     * @throws DomainException si l'utilisateur n'a aucun titre à veiller sur
     *                         cet identifiant
     */
    public function watch(User $utilisateur, string $identifiant, string $canal = 'push'): WatchAlert
    {
        $normalise = $this->normalizer->normalize($identifiant);

        if ($normalise === '') {
            throw new DomainException("Cet identifiant n'est pas exploitable.");
        }

        if (! $this->mayWatch($utilisateur, $normalise)) {
            // Message volontairement identique que l'identifiant existe ou
            // non : le distinguer permettrait de tester l'existence d'un bien
            // par tâtonnement.
            throw new DomainException(
                'Vous ne pouvez veiller que sur un identifiant que vous avez enregistré.'
            );
        }

        $veille = WatchAlert::firstOrNew([
            'user_id' => $utilisateur->id,
            'identifier_normalized' => $normalise,
        ]);

        $veille->fill([
            'channel' => in_array($canal, ['push', 'sms', 'both'], true) ? $canal : 'push',
            'is_active' => true,
        ])->save();

        return $veille;
    }

    /**
     * Désactive sans supprimer : l'historique de déclenchement reste utile, et
     * une veille réactivée ne repart pas de zéro.
     */
    public function unwatch(User $utilisateur, string $identifiant): bool
    {
        $normalise = $this->normalizer->normalize($identifiant);

        $veille = WatchAlert::query()
            ->where('user_id', $utilisateur->id)
            ->where('identifier_normalized', $normalise)
            ->first();

        if (! $veille instanceof WatchAlert) {
            return false;
        }

        $veille->fill(['is_active' => false])->save();

        return true;
    }

    /**
     * Veilleurs à prévenir pour un identifiant, hors période de silence.
     *
     * @return list<WatchAlert>
     */
    public function activeWatchersFor(string $identifiantNormalise, int $cooldownHours): array
    {
        $veilleurs = [];

        $requete = WatchAlert::query()
            ->where('identifier_normalized', $identifiantNormalise)
            ->where('is_active', true);

        foreach ($requete->cursor() as $veille) {
            $enSilence = $veille->last_triggered_at !== null
                && $veille->last_triggered_at->gt(now()->subHours($cooldownHours));

            if (! $enSilence) {
                $veilleurs[] = $veille;
            }
        }

        return $veilleurs;
    }

    /**
     * Vrai si l'utilisateur est, ou a été, détenteur d'un enregistrement
     * portant cet identifiant — actif ou archivé.
     */
    public function mayWatch(User $utilisateur, string $identifiantNormalise): bool
    {
        return Asset::query()
            ->where('identifier_normalized', $identifiantNormalise)
            ->where('owner_user_id', $utilisateur->id)
            ->exists();
    }
}
