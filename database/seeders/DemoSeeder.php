<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DocumentReviewStatus;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Enums\LifeStatus;
use App\Enums\TriggerType;
use App\Enums\TrustLevel;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDocument;
use App\Models\AssetStatusHistory;
use App\Models\CategoryField;
use App\Models\Company;
use App\Models\User;
use App\Services\CategoryRegistry;
use App\Services\IdentifierNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Jeu de démonstration : un bien par statut de vie, tous les niveaux de
 * fiabilité, de quoi montrer le produit aux loueurs pilotes (jalon S7).
 *
 * N'ÉCRIT RIEN DANS LA CHAÎNE D'AUDIT, délibérément. La chaîne se veut
 * opposable : y insérer des actions qui n'ont jamais eu lieu reviendrait à
 * fabriquer de fausses preuves, et rendrait indistinguable, dans une base de
 * démonstration promue en production, ce qui a été joué de ce qui a été fait.
 * Les biens sont donc écrits directement, avec leur ligne d'historique — qui,
 * elle, décrit un état et non une action attestée.
 *
 * Refuse de s'exécuter en production pour la même raison.
 *
 * S'utilise par `php artisan db:seed` ou `$this->seed()` en test : comme tout
 * seeder Laravel, il écrit son résumé sur la console qui l'a lancé et n'est pas
 * prévu pour être instancié à la main — sauf pour éprouver la garde de
 * production ci-dessous, qui s'exécute avant tout affichage.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoSeeder est interdit en production : il crée des comptes et des biens fictifs, '.
                'indistinguables des vrais une fois en base.'
            );
        }

        $this->categories();

        $particulier = $this->compte('+2250700000001', 'Awa Koné', kyc: true);
        $loueur = $this->compte('+2250700000002', 'Ibrahim Traoré', kyc: true);
        $agent = $this->compte('+2250700000009', 'Agent PREUVE', kyc: true, role: UserRole::Agent);
        $admin = $this->compte('+2250700000010', 'Admin PREUVE', kyc: true, role: UserRole::Admin);

        $societe = Company::firstOrCreate(
            ['rccm_number' => 'CI-ABJ-2024-B-12345'],
            [
                'owner_user_id' => $loueur->id,
                'legal_name' => 'Abidjan Location SARL',
                'business_type' => 'car_rental',
                'validation_status' => 'validated',
                'validated_at' => now()->subMonths(4),
                'free_fleet_quota' => 3,
            ],
        );

        // Un bien par statut de vie : la démonstration doit pouvoir montrer
        // chaque verdict tel qu'un acheteur le verra.
        $this->bien($particulier, 'moto', '1M8GDM9AXKP042788', LifeStatus::Active, TrustLevel::Documented, [
            'chassis' => '1M8GDM9AXKP042788',
            'brand_model' => 'Yamaha Crux 110',
        ], documente: true);

        $this->bien($particulier, 'moto', 'JH2PC35061M200018', LifeStatus::Provisional, TrustLevel::Declared, [
            'chassis' => 'JH2PC35061M200018',
            'brand_model' => 'Honda CB 125',
        ], provisoire: true);

        $this->bien($loueur, 'voiture', '5000 AB 01', LifeStatus::Rented, TrustLevel::Verified, [
            'plate' => '5000AB01',
            'brand_model' => 'Toyota Corolla 2019',
        ], societe: $societe, documente: true, verifie: true);

        $this->bien($particulier, 'voiture', 'AA-123-BC', LifeStatus::Transferring, TrustLevel::Documented, [
            'plate' => 'AA123BC',
            'brand_model' => 'Hyundai i10',
        ], documente: true);

        $this->bien($particulier, 'telephone', '490154203237518', LifeStatus::Stolen, TrustLevel::Declared, [
            'imei' => '490154203237518',
            'brand_model' => 'Tecno Spark 10',
        ], volLe: now()->subDays(6));

        $this->bien($loueur, 'moto', '2M8GDM9AXKP042789', LifeStatus::Disputed, TrustLevel::Documented, [
            'chassis' => '2M8GDM9AXKP042789',
            'brand_model' => 'Sanili SL 150',
        ], documente: true);

        $this->bien($particulier, 'telephone', '356938035643809', LifeStatus::EndOfLife, TrustLevel::Declared, [
            'imei' => '356938035643809',
            'brand_model' => 'itel A56',
        ]);

        app(CategoryRegistry::class)->publish();

        $this->command->info('Jeu de démonstration créé :');
        $this->command->table(
            ['Compte', 'Téléphone', 'Rôle'],
            [
                ['Awa Koné (particulier)', $particulier->phone, 'user'],
                ['Ibrahim Traoré (loueur)', $loueur->phone, 'user'],
                ['Agent PREUVE', $agent->phone, 'agent'],
                ['Admin PREUVE', $admin->phone, 'admin'],
            ],
        );
        $this->command->info(
            'Les codes OTP partent dans les journaux tant qu\'aucune passerelle SMS n\'est configurée.'
        );
    }

    /** Catalogue minimal : les trois catégories de la verticale de lancement. */
    private function categories(): void
    {
        $catalogue = [
            ['moto', 'Moto', '🛵', 1, 'chassis', 'N° de châssis'],
            ['voiture', 'Voiture', '🚗', 2, 'plate', 'Plaque d\'immatriculation'],
            ['telephone', 'Téléphone', '📱', 3, 'imei', 'IMEI (*#06#)'],
        ];

        foreach ($catalogue as [$cle, $nom, $icone, $position, $champCle, $champLibelle]) {
            $categorie = AssetCategory::firstOrCreate(
                ['key' => $cle],
                ['name' => $nom, 'icon' => $icone, 'position' => $position, 'is_active' => true],
            );

            CategoryField::firstOrCreate(
                ['asset_category_id' => $categorie->id, 'key' => $champCle],
                [
                    'label' => $champLibelle,
                    'type' => 'identifier',
                    'is_required' => true,
                    'is_canonical_identifier' => true,
                    'position' => 1,
                ],
            );

            CategoryField::firstOrCreate(
                ['asset_category_id' => $categorie->id, 'key' => 'brand_model'],
                [
                    'label' => 'Marque & modèle',
                    'type' => 'text',
                    'is_required' => true,
                    'is_canonical_identifier' => false,
                    'position' => 2,
                ],
            );
        }
    }

    private function compte(string $telephone, string $nom, bool $kyc, ?UserRole $role = null): User
    {
        $utilisateur = User::firstOrCreate(['phone' => $telephone], ['full_name' => $nom]);

        $utilisateur->forceFill(array_filter([
            'phone_verified_at' => now()->subMonths(6),
            'kyc_status' => $kyc ? KycStatus::Verified->value : KycStatus::None->value,
            'kyc_verified_at' => $kyc ? now()->subMonths(5) : null,
            'role' => $role ?? UserRole::User,
        ], fn (mixed $valeur): bool => $valeur !== null))->save();

        return $utilisateur;
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function bien(
        User $proprietaire,
        string $categorie,
        string $identifiant,
        LifeStatus $statut,
        TrustLevel $fiabilite,
        array $attributs,
        ?Company $societe = null,
        bool $documente = false,
        bool $verifie = false,
        bool $provisoire = false,
        ?Carbon $volLe = null,
    ): Asset {
        $enregistreLe = $provisoire ? now()->subDays(4) : now()->subMonths(random_int(2, 14));

        // L'IDENTIFIANT PASSE PAR LE NORMALISEUR, MÊME ICI. Ce chemin
        // court-circuite `AssetRegistrationService` à dessein — un jeu de
        // démonstration n'a pas à écrire dans la chaîne d'audit — mais rien ne
        // dispense de normaliser : une valeur écrite telle quelle donne un bien
        // que la CONSULTATION NE TROUVE JAMAIS, puisqu'elle, elle normalise.
        // Le défaut est muet : le bien s'affiche dans le registre, dans
        // l'inventaire, dans le tableau de flotte, partout sauf là où il sert.
        // Il a été constaté en production le 05/08/2026 sur quatre véhicules
        // dont l'identifiant portait des tirets.
        $identifiant = app(IdentifierNormalizer::class)->normalize($identifiant);

        $bien = Asset::firstOrCreate(
            ['identifier_normalized' => $identifiant, 'active_flag' => 1],
            [
                'public_ref' => 'PRV-'.strtoupper(bin2hex(random_bytes(4))),
                'owner_user_id' => $proprietaire->id,
                'company_id' => $societe?->id,
                'asset_category_key' => $categorie,
                'identifier_type' => $this->typeDe($categorie),
                'identifier_raw' => $identifiant,
                'attributes' => $attributs,
                'trust_level' => $fiabilite,
                'life_status' => $statut,
                'provisional_until' => $provisoire ? $enregistreLe->copy()->addDays(30) : null,
                'stolen_declared_at' => $volLe,
                'stolen_consolidated' => false,
                'trust_verified_at' => $verifie ? now()->subMonth() : null,
                'trust_verified_by' => null,
                'registered_at' => $enregistreLe,
            ],
        );

        if ($bien->wasRecentlyCreated) {
            // Ligne d'ouverture : décrit un état, pas une action attestée — à
            // la différence d'une entrée de chaîne d'audit, qu'un jeu de
            // démonstration n'a pas à fabriquer.
            AssetStatusHistory::create([
                'asset_id' => $bien->id,
                'from_status' => null,
                'to_status' => $statut,
                'to_trust' => $fiabilite,
                'trigger_type' => TriggerType::Owner,
                'actor_user_id' => $proprietaire->id,
                'reason' => 'Jeu de démonstration',
                'created_at' => $enregistreLe->format('Y-m-d H:i:s'),
            ]);
        }

        if ($documente) {
            AssetDocument::firstOrCreate(
                ['asset_id' => $bien->id, 'doc_type' => DocumentType::RegistrationCard->value],
                [
                    'uploaded_by' => $proprietaire->id,
                    'file_ref' => 'demo/carte-grise-'.$bien->id.'.pdf',
                    'file_sha256' => hash('sha256', 'demo-'.$bien->id),
                    'review_status' => DocumentReviewStatus::Accepted->value,
                    'reviewed_at' => $enregistreLe->copy()->addDays(2),
                ],
            );
        }

        return $bien;
    }

    private function typeDe(string $categorie): string
    {
        return match ($categorie) {
            'voiture' => 'plate',
            'telephone' => 'imei',
            default => 'vin',
        };
    }
}
