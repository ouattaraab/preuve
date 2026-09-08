<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\PublicAssetResource;
use App\Models\Asset;

/**
 * Verdict d'une consultation publique.
 *
 * Trois issues distinctes, qu'il ne faut jamais confondre côté interface
 * (ST-0303) : le bien est connu, il est inconnu, ou la consultation n'a pas eu
 * lieu (saisie inexploitable, plafond atteint). Un identifiant inconnu n'est
 * NI un bon NI un mauvais signe — le présenter comme rassurant ferait acheter
 * un bien volé que personne n'a déclaré.
 */
final class LookupResult
{
    private function __construct(
        public readonly bool $found,
        public readonly ?Asset $asset,
        public readonly string $verdict,
        public readonly string $message,
        public readonly bool $rateLimited = false,
        public readonly bool $invalidIdentifier = false,
    ) {}

    public static function found(Asset $asset): self
    {
        return new self(
            found: true,
            asset: $asset,
            verdict: 'known',
            message: $asset->life_status->publicMessage(),
        );
    }

    public static function unknown(): self
    {
        return new self(
            found: false,
            asset: null,
            verdict: 'unknown',
            message: "Ce bien n'est pas enregistré sur PREUVE. Ce n'est ni un bon ni un mauvais signe : ".
                "demandez au vendeur de l'enregistrer, ou faites-le vous-même après achat.",
        );
    }

    /**
     * Plafond atteint.
     *
     * LE MESSAGE DÉPEND DE L'EXISTENCE D'UNE PORTE DE SORTIE. « Réessayez dans
     * un moment » au-dessus d'un défi qui fait passer immédiatement est une
     * contradiction, et c'est la phrase qui est lue en premier : elle ferait
     * renoncer quelqu'un qui pouvait continuer, sur le seul parcours que le
     * produit promet gratuit et sans compte.
     */
    public static function rateLimited(bool $challengeAvailable = false): self
    {
        return new self(
            found: false,
            asset: null,
            verdict: 'rate_limited',
            message: $challengeAvailable
                ? 'Beaucoup de consultations depuis cette connexion. Prouvez que vous '.
                    "n'êtes pas un robot pour continuer tout de suite."
                : 'Trop de consultations depuis cette connexion. Réessayez dans un moment.',
            rateLimited: true,
        );
    }

    public static function invalidIdentifier(): self
    {
        return new self(
            found: false,
            asset: null,
            verdict: 'invalid',
            message: 'Cette saisie ne correspond à aucun format d\'identifiant connu. '.
                'Vérifiez le numéro de châssis, la plaque ou l\'IMEI.',
            invalidIdentifier: true,
        );
    }

    /**
     * Forme publique du verdict. Passe par PublicAssetResource, point de
     * passage unique de la règle d'anonymat : ni le détenteur, ni
     * l'identifiant complet du bien n'en sortent.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'found' => $this->found,
            'message' => $this->message,
            'asset' => $this->asset === null
                ? null
                : (new PublicAssetResource($this->asset))->toArray(request()),
        ];
    }
}
