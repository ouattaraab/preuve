<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Enums\LifeStatus;
use App\Enums\OtpPurpose;
use App\Enums\PaymentProvider;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paiements et réconciliation des webhooks (ST-0801, ST-0802, ST-0806).
 *
 * L'IDEMPOTENCE N'EST PAS UN CONFORT. Les opérateurs de paiement mobile
 * renvoient le même événement plusieurs fois — c'est le comportement normal
 * d'un réseau peu fiable, pas une anomalie. Sans garde, un rapport serait
 * crédité deux fois pour un seul paiement, ou un abonnement prolongé indûment.
 * La garantie ne repose pas sur une vérification applicative, qui perdrait la
 * course entre deux webhooks simultanés, mais sur la contrainte unique
 * (provider, provider_ref) : c'est la base qui tranche.
 *
 * UN ÉTAT DÉFINITIF NE SE REJOUE PAS. Un webhook tardif ne doit pas repasser
 * en « payé » une transaction remboursée, ni l'inverse. Seule une transaction
 * encore en attente change d'état.
 *
 * LE PAYEUR EST TOUJOURS IDENTIFIABLE (règle métier absolue n° 7) : compte
 * connecté, ou nom + e-mail + téléphone VÉRIFIÉ PAR CODE AVANT le paiement.
 * Vérifier après coup ne servirait à rien — l'accès serait déjà ouvert.
 */
final class PaymentService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditChain $auditChain,
        private readonly ReportAccessService $reports,
        private readonly PricingService $tarifs,
        private readonly StolenListingService $listings,
        private readonly AccountOtpSender $codes,
    ) {}

    /**
     * Prépare un paiement de rapport pour un acheteur connecté.
     *
     * @throws DomainException
     */
    public function intendForUser(User $acheteur, Asset $bien, PaymentProvider $operateur): Payment
    {
        return $this->createIntent($operateur, $bien, [
            'user_id' => $acheteur->id,
        ]);
    }

    /**
     * Prépare un paiement pour un motif AUTRE que le rapport détaillé.
     *
     * TOUJOURS RATTACHÉ À UN COMPTE. Déclarer un vol ou publier un bien volé
     * suppose d'en être le détenteur : contrairement au rapport, ces gestes
     * n'ont aucun sens pour un invité, et le paiement doit pouvoir être
     * rapproché de la personne qui en tirera l'effet.
     */
    public function intendFor(
        User $payeur,
        Asset $bien,
        PaymentProvider $operateur,
        PaymentPurpose $motif,
        int $montant,
    ): Payment {
        return $this->createIntent($operateur, $bien, ['user_id' => $payeur->id], $motif, $montant);
    }

    /**
     * Vrai si ce compte a déjà payé ce motif pour ce bien.
     *
     * ON REGARDE UN PAIEMENT ABOUTI, jamais une intention : une intention se
     * crée d'un clic, et s'en contenter reviendrait à offrir le service à qui
     * ouvre la page de paiement sans jamais y régler quoi que ce soit.
     */
    public function hasPaidFor(User $payeur, Asset $bien, PaymentPurpose $motif): bool
    {
        return Payment::query()
            ->where('user_id', $payeur->id)
            ->where('related_id', $bien->id)
            ->where('purpose', $motif->value)
            ->where('status', PaymentStatus::Succeeded->value)
            ->exists();
    }

    /**
     * Prépare un paiement pour un acheteur non inscrit (ST-0802).
     *
     * Le code reçu par SMS est vérifié ICI, avant toute création : c'est le
     * seul moment où la vérification a un sens. Après paiement, le rapport
     * serait déjà accessible.
     *
     * @throws DomainException
     */
    public function intendForGuest(
        Asset $bien,
        PaymentProvider $operateur,
        string $nom,
        string $email,
        string $telephone,
        string $code,
    ): Payment {
        if (trim($nom) === '' || trim($email) === '') {
            throw new DomainException('Nom et adresse e-mail sont obligatoires pour un achat sans compte.');
        }

        // Lève si le code est faux : aucun paiement n'est créé, aucun accès
        // n'est ouvert.
        $this->otp->verify($telephone, $code, OtpPurpose::GuestPayment);

        return $this->createIntent($operateur, $bien, [
            'buyer_name' => trim($nom),
            'buyer_email' => trim($email),
            'buyer_phone' => $this->otp->normalizeDestination($telephone),
        ]);
    }

    /**
     * Réconcilie un événement d'opérateur. Rejouable sans risque : c'est
     * exactement ce qu'on attend d'un webhook.
     *
     * @return Payment|null null si l'événement ne correspond à aucun paiement connu
     *
     * @throws DomainException
     */
    public function reconcile(
        PaymentProvider $operateur,
        string $reference,
        PaymentStatus $etat,
        ?int $paymentId = null,
        ?int $montantRecu = null,
        ?string $deviseRecue = null,
    ): ?Payment {
        if ($reference === '') {
            throw new DomainException('Un événement de paiement sans référence est inexploitable.');
        }

        return DB::transaction(function () use ($operateur, $reference, $etat, $paymentId, $montantRecu, $deviseRecue): ?Payment {
            // Verrou de ligne : deux webhooks simultanés pour la même
            // transaction ne doivent pas la créditer deux fois.
            $paiement = Payment::query()
                ->where('provider', $operateur->value)
                ->where('provider_ref', $reference)
                ->lockForUpdate()
                ->first();

            if (! $paiement instanceof Payment && $paymentId !== null) {
                $paiement = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();
            }

            if (! $paiement instanceof Payment) {
                return null;
            }

            if ($paiement->status->isFinal()) {
                // Déjà tranché : un webhook rejoué ou tardif ne réécrit pas
                // l'histoire d'une transaction — et NE REJOUE PAS non plus la
                // contrepartie. On retourne `null` (et non le paiement) pour que
                // l'appelant ne rappelle pas `fulfill()` : le crédit du rapport
                // et la publication sont idempotents, mais l'envoi du code de
                // déclaration, lui, repartirait à chaque rejeu.
                return null;
            }

            // LE MONTANT RÉGLÉ EST CONFRONTÉ À L'ATTENDU AVANT DE CRÉDITER.
            //
            // La signature authentifie l'émetteur, pas le contenu : un montant
            // partiel, une devise inattendue, ou un tarif modifié entre
            // l'intention et le règlement ne doivent pas ouvrir le service à un
            // prix qui n'est pas le nôtre. On ne vérifie que si l'opérateur
            // fournit ces champs (Paystack le fait ; le format maison, non —
            // absence = non vérifiable, comportement inchangé). En cas d'écart
            // sur un paiement présenté comme réussi : on ne crédite pas, et on
            // journalise pour investigation, plutôt que de trancher à tort.
            if ($etat === PaymentStatus::Succeeded && $montantRecu !== null) {
                $attendu = $paiement->amount_fcfa * 100;
                $deviseConforme = $deviseRecue === null || mb_strtoupper($deviseRecue) === 'XOF';

                if ($montantRecu !== $attendu || ! $deviseConforme) {
                    Log::warning('payment.amount_mismatch', [
                        'payment' => $paiement->id,
                        'expected_minor' => $attendu,
                        'received_minor' => $montantRecu,
                        'currency' => $deviseRecue,
                    ]);

                    return null;
                }
            }

            try {
                $paiement->forceFill([
                    'provider_ref' => $reference,
                    'status' => $etat,
                    'paid_at' => $etat === PaymentStatus::Succeeded ? now() : null,
                ])->save();
            } catch (QueryException $e) {
                // La contrainte unique a tranché : un autre webhook a déjà
                // rattaché cette référence. C'est le résultat attendu, pas une
                // erreur.
                if (! $this->isDuplicateReference($e)) {
                    throw $e;
                }

                return Payment::query()
                    ->where('provider', $operateur->value)
                    ->where('provider_ref', $reference)
                    ->first();
            }

            return $paiement;
        });
    }

    /**
     * Donne suite à un paiement abouti : ouvre l'accès au rapport. Idempotent
     * lui aussi — l'accès n'est créé qu'une fois.
     */
    public function fulfill(Payment $paiement): void
    {
        if ($paiement->status !== PaymentStatus::Succeeded) {
            return;
        }

        $bien = Asset::query()->whereKey($paiement->related_id)->first();

        if (! $bien instanceof Asset) {
            return;
        }

        // CHAQUE MOTIF A SA CONTREPARTIE, ET ELLE EST HONORÉE ICI. C'est le
        // seul endroit où un paiement abouti se transforme en service rendu :
        // un motif oublié dans ce `match` produirait un encaissement sans
        // contrepartie — de l'argent pris pour rien, et personne pour s'en
        // apercevoir avant une réclamation.
        match ($paiement->purpose) {
            PaymentPurpose::DetailedReport => $this->reports->grant($paiement, $bien),
            PaymentPurpose::TheftListing => $this->publierLeBienVole($paiement, $bien),
            PaymentPurpose::TheftDeclaration => $this->envoyerLeCodeDeDeclaration($paiement),
            // Les autres motifs se règlent ailleurs : la place
            // d'enregistrement au moment de l'enregistrement, les frais de
            // dossier au dépôt, l'abonnement flotte au décompte mensuel.
            default => null,
        };
    }

    /**
     * Publie un bien volé dont la mise en avant vient d'être réglée.
     *
     * ON REVÉRIFIE QUE LE BIEN EST VOLÉ. Entre l'ouverture du paiement et sa
     * confirmation, le détenteur a pu retrouver son bien et lever la
     * déclaration : le publier alors mettrait sur la liste des biens volés un
     * bien qui ne l'est plus, et la liste perdrait ce qui fait sa valeur.
     */
    private function publierLeBienVole(Payment $paiement, Asset $bien): void
    {
        if ($bien->life_status !== LifeStatus::Stolen || $paiement->user_id === null) {
            return;
        }

        $detenteur = User::query()->whereKey($paiement->user_id)->first();

        if ($detenteur instanceof User) {
            $this->listings->publish($bien, $detenteur);
        }
    }

    /**
     * Le règlement du péage de déclaration est reçu : le code peut partir.
     *
     * C'EST ICI QUE L'ORDRE DEMANDÉ SE JOUE — payer, PUIS recevoir le code.
     * L'émettre avant aurait fait courir son expiration pendant la traversée
     * d'une page bancaire : l'utilisateur serait revenu, aurait payé, et aurait
     * trouvé un code périmé.
     *
     * LE CODE NE DÉCLARE RIEN À LUI SEUL. Il ouvre le geste ; c'est
     * `AssetLifecycleService::declareStolen()` qui change le statut, après
     * l'avoir vérifié. Un paiement n'est pas une preuve d'identité, et un
     * webhook n'est pas un utilisateur.
     *
     * UN ENVOI QUI ÉCHOUE NE FAIT PAS ÉCHOUER LE WEBHOOK : le droit acquis est
     * enregistré dans `payments`, et l'utilisateur pourra redemander un code
     * sans repayer. Lever ici ferait rejouer l'opérateur, indéfiniment.
     */
    private function envoyerLeCodeDeDeclaration(Payment $paiement): void
    {
        if ($paiement->user_id === null) {
            return;
        }

        $payeur = User::query()->whereKey($paiement->user_id)->first();

        if ($payeur instanceof User) {
            $this->codes->send($payeur, OtpPurpose::SensitiveAction);
        }
    }

    /**
     * @param  array<string, mixed>  $acheteur
     */
    private function createIntent(
        PaymentProvider $operateur,
        Asset $bien,
        array $acheteur,
        ?PaymentPurpose $motif = null,
        ?int $montant = null,
    ): Payment {
        // LE RAPPORT RESTE LE DÉFAUT, et c'est ce qui rend ce changement sans
        // conséquence pour l'existant : les appels d'origine ne passent ni
        // motif ni montant et obtiennent exactement ce qu'ils obtenaient.
        $motif ??= PaymentPurpose::DetailedReport;
        $montant ??= $this->reportPrice();

        $paiement = Payment::create([
            ...$acheteur,
            'purpose' => $motif,
            'related_id' => $bien->id,
            'amount_fcfa' => $montant,
            'provider' => $operateur,
            'status' => PaymentStatus::Pending,
        ]);

        $this->auditChain->append(
            $paiement->user_id === null ? ActorType::System : ActorType::User,
            $paiement->user_id,
            'payment.intended',
            'payment',
            $paiement->id,
            [
                'purpose' => $motif->value,
                'provider' => $operateur->value,
                'amount_fcfa' => $paiement->amount_fcfa,
                'asset_id' => $bien->id,
                // Jamais l'identité de l'acheteur invité : la chaîne est
                // inaltérable, et son anonymat doit lui survivre.
            ],
        );

        return $paiement;
    }

    /**
     * Le prix du rapport vient des TARIFS, réglables sans livraison.
     *
     * ZÉRO EST RENDU TEL QUEL : c'est un rapport gratuit, et c'est à l'appelant
     * de ne pas ouvrir de paiement pour zéro franc. Le remplacer par une valeur
     * de repli rétablirait un prix que l'administrateur vient de supprimer.
     */
    private function reportPrice(): int
    {
        return $this->tarifs->amount('report');
    }

    private function isDuplicateReference(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'uq_payments_provider_ref');
    }
}
