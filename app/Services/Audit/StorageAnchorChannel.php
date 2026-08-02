<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Ancrage par dépôt sur un stockage objet séparé.
 *
 * Le disque se configure à part du stockage des documents, et c'est
 * volontaire : l'ancrage doit atterrir là où l'exploitant de la base ne peut
 * pas réécrire l'histoire — idéalement un bucket en écriture unique (WORM) ou
 * chez un autre fournisseur. Le pointer sur le même disque que les
 * justificatifs annulerait l'intérêt du canal.
 *
 * Le fichier porte la date dans son nom et n'est jamais écrasé : deux ancrages
 * du même jour cohabitent plutôt que le second n'efface le premier.
 */
final class StorageAnchorChannel implements AnchorChannel
{
    public const DISK_SETTING = 'audit.anchor.storage_disk';

    public const PREFIX_SETTING = 'audit.anchor.storage_prefix';

    private const DEFAULT_PREFIX = 'audit-anchors';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function name(): string
    {
        return 'storage';
    }

    public function isConfigured(): bool
    {
        return $this->disk() !== null;
    }

    public function publish(AnchorDocument $document): void
    {
        $disque = $this->disk();

        if ($disque === null) {
            throw new RuntimeException(
                "Aucun disque d'ancrage configuré : l'ancrage ne peut pas quitter la plateforme."
            );
        }

        $chemin = $this->prefix().'/'.$document->filename();

        if (Storage::disk($disque)->exists($chemin)) {
            // Deux ancrages dans la même seconde : le second prend un suffixe
            // plutôt que d'écraser le premier. Écraser reviendrait à effacer
            // une preuve avec une autre.
            $chemin = $this->prefix().'/'.$document->fingerprint().'-'.$document->filename();
        }

        $ecrit = Storage::disk($disque)->put($chemin, $document->toText());

        if ($ecrit === false) {
            throw new RuntimeException(
                "Le dépôt de l'ancrage sur le disque « {$disque} » a échoué : rien n'a quitté la plateforme."
            );
        }
    }

    private function disk(): ?string
    {
        $disque = $this->settings->get(self::DISK_SETTING);

        return is_string($disque) && $disque !== '' ? $disque : null;
    }

    private function prefix(): string
    {
        $prefixe = $this->settings->get(self::PREFIX_SETTING);

        return is_string($prefixe) && $prefixe !== '' ? trim($prefixe, '/') : self::DEFAULT_PREFIX;
    }
}
