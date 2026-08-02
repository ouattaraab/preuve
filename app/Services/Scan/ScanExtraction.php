<?php

declare(strict_types=1);

namespace App\Services\Scan;

/**
 * Ce qu'un lecteur a cru voir sur une carte grise ou une facture.
 *
 * L'objet porte des CANDIDATS, pas un identifiant. Le lecteur ne sait pas ce
 * qui fait un identifiant valide en Côte d'Ivoire — un VIN se reconnaît à son
 * chiffre de contrôle, une plaque à son format — et ce jugement appartient à
 * `IdentifierNormalizer`, qui est déjà le seul à le porter pour
 * l'enregistrement. Laisser chaque adaptateur de fournisseur trancher ferait
 * dériver la définition d'un identifiant valide au gré des tiers.
 */
final class ScanExtraction
{
    /**
     * L'ORIGINE DES CANDIDATS COMPTE AUTANT QUE LEUR VALEUR. Une chaîne issue
     * d'un champ nommé « châssis » est adossée à la lecture d'un formulaire ;
     * une chaîne ramassée dans le texte de la page peut être un numéro de
     * téléphone, une référence de tampon ou le nom d'une commune. Les deux ne
     * méritent donc pas la même confiance, et les mélanger ferait proposer
     * « ABIDJAN01 » comme numéro de série d'un véhicule.
     *
     * @param  list<string>  $candidates  issus de champs nommés par le fournisseur
     * @param  list<string>  $tokens  ramassés dans le texte reconnu de la page
     * @param  array<string, string>  $attributes  champs annexes lisibles (marque,
     *                                             modèle, mise en circulation)
     * @param  int|null  $confidence  confiance du fournisseur, de 0 à 100
     * @param  bool  $failed  vrai si rien n'a pu être lu
     */
    public function __construct(
        public readonly array $candidates = [],
        public readonly array $tokens = [],
        public readonly array $attributes = [],
        public readonly ?int $confidence = null,
        public readonly bool $failed = false,
    ) {}

    public static function unreadable(): self
    {
        return new self(failed: true);
    }
}
