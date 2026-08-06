<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Models\Asset;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * La liste publique des biens volés, et la mise en avant qui l'alimente
 * (ST-0805).
 *
 * CE QUE CE SERVICE MONNAIE, ET CE QU'IL NE MONNAIE PAS. Déclarer un vol rend
 * le bien invendable pour quiconque VÉRIFIE son numéro : c'est la protection,
 * elle est immédiate, et elle ne se paie pas. Figurer sur la liste que tout le
 * monde parcourt est autre chose — de la VISIBILITÉ. C'est ce service-là qu'on
 * facture, et c'est précisément ce qui permet à la protection de rester
 * gratuite.
 *
 * LA PUBLICATION EXPOSE L'IDENTIFIANT RÉEL, et c'est assumé. Le registre refuse
 * par ailleurs qu'on l'énumère — un concessionnaire qui balaierait les numéros
 * cartographierait le parc. Ici, c'est l'inverse : le détenteur DEMANDE
 * l'exposition, parce qu'un bien volé qu'on ne peut pas reconnaître ne se
 * retrouve jamais. Le consentement fait toute la différence, et il est daté.
 *
 * ELLE NE NOMME PERSONNE. Ni le détenteur dépouillé, ni qui consulte. Un
 * annuaire de victimes serait une liste de personnes à démarcher — ou pire, à
 * viser une seconde fois.
 */
final class StolenListingService
{
    public function __construct(
        private readonly PricingService $tarifs,
        private readonly AuditChain $auditChain,
    ) {}

    /** Ce que coûte la mise en avant, tel que l'exploitant l'a réglé. */
    public function price(): int
    {
        return $this->tarifs->amount('theft_listing');
    }

    public function isFree(): bool
    {
        return $this->tarifs->isFree('theft_listing');
    }

    /**
     * Publie un bien volé sur la liste.
     *
     * @throws DomainException
     */
    public function publish(Asset $bien, User $detenteur): Asset
    {
        if ($bien->owner_user_id !== $detenteur->id) {
            throw new DomainException('Seul le détenteur enregistré peut publier ce bien.');
        }

        // ON NE PUBLIE QUE CE QUI EST VOLÉ. Une liste de biens volés qui
        // contiendrait des biens sains ne serait plus consultée, et celui qui
        // aurait payé pour y figurer n'aurait acheté que du bruit.
        if ($bien->life_status !== LifeStatus::Stolen) {
            throw new DomainException(
                'Ce bien n\'est pas déclaré volé : la liste ne montre que des biens volés.'
            );
        }

        if ($bien->stolen_listed_at !== null) {
            // Déjà publié : on ne facture pas deux fois la même visibilité.
            return $bien;
        }

        $this->auditChain->append(
            ActorType::User,
            $detenteur->id,
            'asset.stolen_listed',
            'asset',
            $bien->id,
            // Ni l'identifiant ni le détenteur : la chaîne est inaltérable, et
            // la publication peut être retirée alors qu'une trace ne s'efface
            // pas. On consigne le GESTE, pas son contenu.
            ['price_fcfa' => $this->price()],
        );

        $bien->forceFill(['stolen_listed_at' => now()])->save();

        return $bien;
    }

    /**
     * Retire un bien de la liste.
     *
     * LE RETRAIT EST TOUJOURS GRATUIT ET IMMÉDIAT. Un bien retrouvé, une
     * déclaration faite par erreur : laisser une publication payante survivre à
     * son motif exposerait un identifiant pour rien. Ce qui a été payé est la
     * visibilité passée, pas un engagement à la subir.
     */
    public function withdraw(Asset $bien, User $detenteur): Asset
    {
        if ($bien->owner_user_id !== $detenteur->id) {
            throw new DomainException('Seul le détenteur enregistré peut retirer ce bien.');
        }

        if ($bien->stolen_listed_at === null) {
            return $bien;
        }

        $this->auditChain->append(
            ActorType::User,
            $detenteur->id,
            'asset.stolen_unlisted',
            'asset',
            $bien->id,
            [],
        );

        $bien->forceFill(['stolen_listed_at' => null])->save();

        return $bien;
    }

    /**
     * La liste publique, paginée et cherchable.
     *
     * @param  string|null  $recherche  fragment d'identifiant, de marque ou de
     *                                  modèle. On CHERCHE dedans plutôt que
     *                                  d'exiger le numéro exact : quelqu'un qui
     *                                  croit reconnaître une moto n'a souvent
     *                                  qu'un bout de plaque.
     * @return LengthAwarePaginator<int, Asset>
     */
    public function browse(?string $recherche = null, int $parPage = 20): LengthAwarePaginator
    {
        $propre = $recherche === null ? '' : trim($recherche);
        $terme = $propre === '' ? null : '%'.mb_strtoupper($propre).'%';

        return Asset::query()
            ->where('life_status', LifeStatus::Stolen->value)
            ->whereNotNull('stolen_listed_at')
            ->where('active_flag', 1)
            ->when($terme !== null, function ($requete) use ($terme): void {

                // `identifier_normalized` et non `identifier_raw` : quelqu'un
                // qui tape « AA 123 BC » doit retrouver « AA123BC ».
                $requete->where(function ($ou) use ($terme): void {
                    $ou->where('identifier_normalized', 'like', $terme)
                        ->orWhere('attributes', 'like', $terme);
                });
            })
            // LES PLUS RÉCENTS D'ABORD : un vol d'hier se retrouve encore, un
            // vol d'il y a deux ans beaucoup moins.
            ->orderByDesc('stolen_declared_at')
            ->paginate($parPage);
    }

    /**
     * Ce qu'on montre d'un bien volé publié.
     *
     * L'IDENTIFIANT Y FIGURE, ET C'EST TOUT LE POINT : sans lui, personne ne
     * peut reconnaître le bien qu'on lui propose. Le détenteur, lui, n'y figure
     * jamais — la liste sert à retrouver des biens, pas à désigner des victimes.
     *
     * @return array<string, mixed>
     */
    public function present(Asset $bien): array
    {
        // `getAttribute('attributes')` ET NON `$bien->attributes` : le nom
        // entre en collision avec la propriété interne d'Eloquent, et l'accès
        // direct rend le tableau brut du modèle entier au lieu de la colonne.
        // Le projet s'est déjà fait prendre — voir OwnedAssetResource.
        $brut = $bien->getAttribute('attributes');
        $attributs = is_array($brut) ? $brut : [];

        return [
            'public_ref' => $bien->public_ref,
            // Le numéro tel que le détenteur l'a saisi : c'est celui qui est
            // gravé sur le bien, et donc celui qu'on lira dessus.
            'identifier' => $bien->identifier_raw,
            'identifier_type' => $bien->identifier_type,
            'category' => $bien->asset_category_key,
            'brand_model' => $this->marque($attributs),
            'stolen_declared_at' => $bien->stolen_declared_at?->toIso8601String(),
            'listed_at' => $bien->stolen_listed_at?->toIso8601String(),
            'consolidated' => (bool) $bien->stolen_consolidated,
            'days_since' => $bien->stolen_declared_at === null
                ? null
                : (int) Carbon::parse($bien->stolen_declared_at)->diffInDays(now()),
        ];
    }

    /**
     * Marque et modèle, s'ils ont été renseignés.
     *
     * @param  array<mixed>  $attributs
     */
    private function marque(array $attributs): ?string
    {
        $morceaux = [];

        foreach (['brand_model', 'make', 'marque', 'model', 'modele'] as $cle) {
            $valeur = $attributs[$cle] ?? null;

            if (is_string($valeur) && trim($valeur) !== '') {
                $morceaux[] = trim($valeur);
            }
        }

        return $morceaux === [] ? null : implode(' ', array_unique($morceaux));
    }
}
