<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssetCategory;
use App\Models\CategoryField;
use App\Services\CategoryRegistry;
use Illuminate\Console\Command;

/**
 * Publie le catalogue des catégories de biens.
 *
 * SANS ELLE, LA PLATEFORME NE PEUT RIEN ENREGISTRER. Les catégories et leurs
 * champs sont servis par configuration distante (décision D6) : l'application
 * mobile ne code aucun type de bien en dur, et `POST /assets` refuse une
 * catégorie inconnue. Or le seul écrivain de ce catalogue était le jeu de
 * démonstration — qui refuse la production, à raison. Le parcours central du
 * produit était donc injouable sur une installation neuve, ce qui ne se voyait
 * qu'au premier enregistrement tenté.
 *
 * ELLE EST IDEMPOTENTE et ne détruit rien : relancée, elle complète sans
 * écraser. Une catégorie retirée du catalogue ci-dessous n'est pas supprimée —
 * des biens y sont rattachés, et leur catégorie ne doit pas disparaître sous
 * eux. La retirer du service se fait en la désactivant, pas en l'effaçant.
 */
final class PublishCategories extends Command
{
    protected $signature = 'preuve:publish-categories';

    protected $description = 'Publie les catégories de biens et leurs champs (configuration distante)';

    /**
     * Catalogue de lancement. L'identifiant canonique de chaque catégorie est
     * celui qui porte la règle « un identifiant = un enregistrement actif » :
     * le châssis pour une moto, la plaque pour une voiture, l'IMEI pour un
     * téléphone.
     *
     * @var list<array{key: string, name: string, icon: string, position: int, identifier: string, identifier_label: string}>
     */
    private const CATALOGUE = [
        ['key' => 'moto', 'name' => 'Moto', 'icon' => '🛵', 'position' => 1,
            'identifier' => 'chassis', 'identifier_label' => 'N° de châssis'],
        ['key' => 'voiture', 'name' => 'Voiture', 'icon' => '🚗', 'position' => 2,
            'identifier' => 'plate', 'identifier_label' => 'Plaque d\'immatriculation'],
        ['key' => 'telephone', 'name' => 'Téléphone', 'icon' => '📱', 'position' => 3,
            'identifier' => 'imei', 'identifier_label' => 'IMEI (composez *#06#)'],
    ];

    public function handle(CategoryRegistry $registre): int
    {
        foreach (self::CATALOGUE as $entree) {
            $categorie = AssetCategory::firstOrCreate(
                ['key' => $entree['key']],
                [
                    'name' => $entree['name'],
                    'icon' => $entree['icon'],
                    'position' => $entree['position'],
                    'is_active' => true,
                ],
            );

            CategoryField::firstOrCreate(
                ['asset_category_id' => $categorie->id, 'key' => $entree['identifier']],
                [
                    'label' => $entree['identifier_label'],
                    'type' => 'identifier',
                    'is_required' => true,
                    // Un seul champ canonique par catégorie : c'est lui qui
                    // porte l'unicité de l'enregistrement actif.
                    'is_canonical_identifier' => true,
                    'position' => 1,
                ],
            );

            CategoryField::firstOrCreate(
                ['asset_category_id' => $categorie->id, 'key' => 'brand_model'],
                [
                    'label' => 'Marque et modèle',
                    'type' => 'text',
                    'is_required' => true,
                    'is_canonical_identifier' => false,
                    'position' => 2,
                ],
            );

            $this->components->twoColumnDetail(
                $entree['icon'].' '.$entree['name'],
                $entree['identifier_label'],
            );
        }

        // La version sert d'ETag : sans publication, l'application mobile
        // garderait son catalogue en cache et ne verrait jamais le nouveau.
        $version = $registre->publish();

        $this->components->info("Catalogue publié, version {$version}.");

        return self::SUCCESS;
    }
}
