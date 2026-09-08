<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Canal de publication d'un ancrage hors de la plateforme.
 *
 * Ce qui donne sa valeur à un ancrage, ce n'est pas son contenu — l'empreinte
 * de tête est publique et sans secret — mais le fait qu'il ait quitté le
 * système, à une date, vers un support que celui qui contrôle la base ne
 * contrôle pas. Un canal qui écrirait sur la même machine ne prouverait rien.
 */
interface AnchorChannel
{
    /** Nom court du canal, consigné au registre. */
    public function name(): string;

    /** Vrai si le canal est configuré et utilisable. */
    public function isConfigured(): bool;

    /**
     * Publie l'ancrage. Doit lever en cas d'échec : un canal qui échoue en
     * silence ferait croire à une protection inexistante.
     */
    public function publish(AnchorDocument $document): void;
}
