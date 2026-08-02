<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Carbon;

/**
 * Le constat d'ancrage, tel qu'il part vers l'extérieur.
 *
 * Délibérément lisible par un humain : il sera relu des mois plus tard, peut-être
 * par un magistrat, peut-être depuis une boîte mail — sans accès au code ni à
 * la base. Un format binaire ou une charge JSON compacte demanderait un outil
 * pour être compris, et un ancrage incompréhensible ne sert à rien.
 *
 * Ne contient AUCUNE donnée personnelle : seulement des empreintes, un
 * compteur et une date. Il peut donc être conservé indéfiniment, chez un tiers,
 * sans conflit avec le droit à l'effacement (Loi 2013-450).
 */
final class AnchorDocument
{
    public function __construct(
        public readonly Carbon $anchoredAt,
        public readonly ?int $headId,
        public readonly string $headChainHash,
        public readonly int $entryCount,
        public readonly string $application,
    ) {}

    public function subject(): string
    {
        return sprintf(
            'PREUVE — ancrage de la chaîne d\'audit du %s (entrée #%s)',
            $this->anchoredAt->format('d/m/Y'),
            $this->headId === null ? '—' : (string) $this->headId,
        );
    }

    public function filename(): string
    {
        return sprintf('ancrage-%s.txt', $this->anchoredAt->format('Y-m-d-His'));
    }

    /**
     * Empreinte du constat lui-même : permet de vérifier qu'un ancrage
     * retrouvé chez un tiers n'a pas été retouché entre-temps.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->anchoredAt->toIso8601String(),
            (string) ($this->headId ?? 0),
            $this->headChainHash,
            (string) $this->entryCount,
        ]));
    }

    public function toText(): string
    {
        return <<<TEXTE
        PREUVE — ancrage de la chaîne d'audit
        =====================================

        Application       : {$this->application}
        Date d'ancrage    : {$this->anchoredAt->toIso8601String()} (UTC)
        Entrée de tête    : {$this->headLabel()}
        Empreinte de tête : {$this->headChainHash}
        Nombre d'entrées  : {$this->entryCount}
        Empreinte du constat : {$this->fingerprint()}

        À QUOI SERT CE MESSAGE
        ----------------------
        La chaîne d'audit de PREUVE lie chaque action à la précédente par une
        empreinte SHA-256. L'algorithme est public et sans secret : quiconque
        disposerait d'un accès en écriture à la base pourrait reconstruire une
        chaîne cohérente de bout en bout. Ce message, horodaté par un tiers et
        conservé hors de la plateforme, est ce qui rend cette reconstruction
        détectable : l'empreinte de tête ci-dessus ne peut plus changer pour la
        date indiquée.

        CONSERVEZ CE MESSAGE SANS LE MODIFIER.
        Ne le supprimez pas : c'est la pièce de comparaison.

        Ce message ne contient aucune donnée personnelle — seulement des
        empreintes, un compteur et une date.
        TEXTE;
    }

    private function headLabel(): string
    {
        return $this->headId === null
            ? 'aucune (chaîne vide à cette date)'
            : '#'.$this->headId;
    }
}
