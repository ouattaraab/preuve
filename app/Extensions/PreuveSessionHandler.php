<?php

declare(strict_types=1);

namespace App\Extensions;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Gestionnaire de session « base de données » de PREUVE.
 *
 * Identique à celui de Laravel, à une exception près : la colonne
 * `ip_address` de la table `sessions` reste toujours `null`. Le
 * gestionnaire d'origine (Illuminate\Session\DatabaseSessionHandler) y
 * écrit l'adresse IP du client en clair à chaque requête — ce qui viole la
 * règle absolue de PREUVE : l'adresse IP n'existe jamais en clair, elle
 * n'est manipulée qu'après hachage (`hash('sha256', $ip . $selQuotidien)`).
 * Ce n'est pas un oubli : la table de session n'a aucun besoin métier de
 * l'IP, donc on ne la stocke pas (principe de minimisation).
 *
 * Le `user_agent` reste conservé : nettement moins identifiant qu'une IP,
 * et utile pour l'investigation en cas d'incident de sécurité.
 */
class PreuveSessionHandler extends DatabaseSessionHandler
{
    /**
     * Ajoute les informations de requête à la charge utile de session,
     * sans jamais y inclure l'adresse IP en clair.
     *
     * @param  array<string, mixed>  $payload
     * @return $this
     */
    protected function addRequestInformation(&$payload)
    {
        if ($this->container?->bound('request')) {
            $payload = array_merge($payload, [
                // Volontairement null : voir le commentaire de classe.
                'ip_address' => null,
                'user_agent' => $this->userAgent(),
            ]);
        }

        return $this;
    }
}
