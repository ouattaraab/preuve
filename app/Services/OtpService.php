<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\OtpRefus;
use App\Exceptions\OtpRefuseException;
use App\Models\OtpCode;
use App\Services\Otp\OtpSender;
use Illuminate\Support\Facades\DB;

/**
 * Émission et vérification des codes à usage unique (ST-0101 / ST-0102).
 *
 * Il n'y a pas de mot de passe au MVP : ce service est la seule preuve
 * d'identité de la plateforme. Trois propriétés le tiennent :
 *
 * 1. Le code n'est jamais stocké en clair, ni même sous simple SHA-256 : six
 *    chiffres n'offrent qu'un million de valeurs, qu'un dump de base suffirait
 *    à renverser en quelques secondes. L'empreinte est un HMAC porté par
 *    APP_KEY, et couvre aussi la destination et le motif — une ligne recopiée
 *    vers un autre numéro ou un autre motif ne vaut donc rien.
 * 2. Le verrouillage anti-brute-force porte sur la DESTINATION, jamais sur la
 *    seule ligne de code : sinon redemander un code remettrait le compteur de
 *    tentatives à zéro, et le plafond ne coûterait à l'attaquant qu'un
 *    aller-retour de plus.
 * 3. Aucune réponse ne révèle si un compte existe pour cette destination
 *    (voir OtpRefus) : le parcours de connexion deviendrait sinon un service
 *    d'énumération de numéros de téléphone.
 *
 * Ce service ne journalise rien dans la chaîne d'audit : c'est l'action métier
 * qui en découle — ouverture de session, création de compte, confirmation d'un
 * transfert — qui est journalisée par son appelant. Journaliser chaque envoi
 * de SMS ferait passer tout le trafic d'authentification par le verrou nommé
 * global de la chaîne d'audit, dont le plafond mesuré est d'une quinzaine
 * d'actions simultanées (voir AuditChain::transaction()).
 */
final class OtpService
{
    /**
     * Indicatif appliqué à un numéro composé localement. La verticale de
     * lancement est ivoirienne ; un numéro déjà préfixé est respecté tel quel.
     */
    private const DEFAULT_COUNTRY_CODE = '+225';

    public function __construct(private readonly OtpSender $sender) {}

    /**
     * Émet un code et l'achemine. Le code retourné n'apparaît jamais dans la
     * valeur de retour : seul le destinataire le reçoit.
     *
     * @throws OtpRefuseException
     */
    public function request(
        string $destination,
        OtpPurpose $purpose,
        OtpChannel $channel = OtpChannel::Sms,
    ): OtpCode {
        $destination = $this->normalizeDestination($destination);

        $this->assertNotLocked($destination);
        $this->assertRequestRateAcceptable($destination);

        $code = $this->generateCode();

        // Les codes encore valides pour ce motif sont périmés sur-le-champ :
        // deux codes simultanément valides doubleraient la surface d'attaque
        // et rendraient l'expiration imprévisible pour l'utilisateur.
        OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $challenge = OtpCode::create([
            'destination' => $destination,
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => $this->hash($destination, $purpose, $code),
            'attempts' => 0,
            'max_attempts' => $this->maxAttempts(),
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);

        $this->sender->send($destination, $code, $purpose);

        return $challenge;
    }

    /**
     * Vérifie un code et le consomme. Un code consommé ne vaut plus rien, même
     * avant son expiration : sans cela, un code intercepté resterait rejouable
     * pendant tout le reste de sa durée de vie.
     *
     * @throws OtpRefuseException
     */
    public function verify(string $destination, string $code, OtpPurpose $purpose): OtpCode
    {
        $destination = $this->normalizeDestination($destination);

        $this->assertNotLocked($destination);

        $attendu = $this->hash($destination, $purpose, $code);

        // Transaction courte avec verrou de ligne : deux vérifications
        // simultanées du même code doivent en consommer une seule, sans quoi
        // un code intercepté serait exploitable en parallèle de son
        // destinataire légitime.
        //
        // Le refus est RETOURNÉ, jamais levé depuis l'intérieur de la
        // transaction : une exception y déclencherait un ROLLBACK qui
        // annulerait la tentative échouée qu'on vient justement de
        // comptabiliser — le compteur resterait à zéro et le verrouillage
        // anti-brute-force ne se déclencherait jamais.
        /** @var array{challenge: OtpCode|null, refus: OtpRefus|null} $resultat */
        $resultat = DB::transaction(function () use ($destination, $purpose, $attendu): array {
            $challenge = OtpCode::query()
                ->where('destination', $destination)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return ['challenge' => null, 'refus' => OtpRefus::CodeInvalide];
            }

            if ($challenge->expires_at->isPast()) {
                return ['challenge' => null, 'refus' => OtpRefus::CodeExpire];
            }

            // hash_equals : la comparaison ne doit pas fuir, par sa durée, le
            // nombre de caractères corrects du code fourni.
            if (! hash_equals($challenge->code_hash, $attendu)) {
                $this->registerFailedAttempt($challenge);

                return ['challenge' => null, 'refus' => OtpRefus::CodeInvalide];
            }

            $challenge->consumed_at = now();
            $challenge->save();

            return ['challenge' => $challenge, 'refus' => null];
        });

        if ($resultat['refus'] !== null || $resultat['challenge'] === null) {
            throw new OtpRefuseException($resultat['refus'] ?? OtpRefus::CodeInvalide);
        }

        return $resultat['challenge'];
    }

    /**
     * Numéro au format E.164. Sans cette normalisation, « 07 00 00 00 01 » et
     * « +225 07-00-00-00-01 » seraient deux destinations distinctes et le
     * plafond de tentatives se contournerait par un simple espace.
     *
     * @throws OtpRefuseException si le numéro n'est pas exploitable
     */
    public function normalizeDestination(string $destination): string
    {
        $numero = preg_replace('/[\s().\-]/', '', trim($destination)) ?? '';

        if (str_starts_with($numero, '00')) {
            $numero = '+'.mb_substr($numero, 2);
        }

        // Les numéros ivoiriens s'écrivent à dix chiffres depuis 2021, sans
        // préfixe interurbain à retirer : le numéro composé localement se
        // préfixe donc tel quel.
        if (! str_starts_with($numero, '+')) {
            $numero = self::DEFAULT_COUNTRY_CODE.$numero;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $numero) !== 1) {
            throw new OtpRefuseException(OtpRefus::DestinationInvalide);
        }

        return $numero;
    }

    /**
     * Verrou actif sur la destination, tous motifs confondus : c'est
     * l'abonné qu'on protège, pas une ligne de la table.
     *
     * @throws OtpRefuseException
     */
    private function assertNotLocked(string $destination): void
    {
        $verrou = OtpCode::query()
            ->where('destination', $destination)
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->orderByDesc('locked_until')
            ->first();

        if ($verrou?->locked_until !== null) {
            throw new OtpRefuseException(OtpRefus::Verrouille, $verrou->locked_until);
        }
    }

    /** @throws OtpRefuseException */
    private function assertRequestRateAcceptable(string $destination): void
    {
        $dernier = OtpCode::query()
            ->where('destination', $destination)
            ->orderByDesc('id')
            ->first();

        if ($dernier?->created_at !== null) {
            $prochainEnvoi = $dernier->created_at->copy()->addSeconds($this->resendDelaySeconds());

            if ($prochainEnvoi->isFuture()) {
                throw new OtpRefuseException(OtpRefus::TropDeDemandes, $prochainEnvoi);
            }
        }

        $envoisRecents = OtpCode::query()
            ->where('destination', $destination)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($envoisRecents >= $this->maxRequestsPerHour()) {
            throw new OtpRefuseException(OtpRefus::TropDeDemandes, now()->addHour());
        }
    }

    /**
     * Comptabilise l'échec et verrouille la destination si les tentatives sont
     * épuisées. La durée croît avec le nombre de verrouillages déjà subis sur
     * la période d'observation : une erreur de saisie coûte quelques minutes,
     * un automate qui s'acharne finit à la journée.
     */
    private function registerFailedAttempt(OtpCode $challenge): void
    {
        $challenge->attempts++;

        if ($challenge->attempts >= $challenge->max_attempts) {
            $challenge->locked_until = now()->addMinutes($this->lockoutMinutes($challenge->destination));
        }

        $challenge->save();
    }

    private function lockoutMinutes(string $destination): int
    {
        $paliers = $this->lockoutSchedule();

        $verrouillagesRecents = OtpCode::query()
            ->where('destination', $destination)
            ->whereNotNull('locked_until')
            ->where('created_at', '>', now()->subHours($this->lockoutWindowHours()))
            ->count();

        return $paliers[min($verrouillagesRecents, count($paliers) - 1)];
    }

    /**
     * Empreinte du code. Le HMAC est porté par APP_KEY : un dump de base sans
     * la clé ne permet pas de retrouver un code à six chiffres par force
     * brute. La destination et le motif entrent dans le message haché, ce qui
     * rend l'empreinte inutilisable ailleurs que sur la ligne qui la porte.
     */
    private function hash(string $destination, OtpPurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', $purpose->value.'|'.$destination.'|'.$code, $this->secret());
    }

    private function secret(): string
    {
        $cle = config('app.key');

        return is_string($cle) ? $cle : '';
    }

    /** Code décimal de longueur fixe, tiré d'une source cryptographique. */
    private function generateCode(): string
    {
        $longueur = $this->codeLength();

        return str_pad(
            (string) random_int(0, 10 ** $longueur - 1),
            $longueur,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function codeLength(): int
    {
        return $this->configInt('otp.length', 6);
    }

    private function ttlMinutes(): int
    {
        return $this->configInt('otp.ttl_minutes', 5);
    }

    private function maxAttempts(): int
    {
        return $this->configInt('otp.max_attempts', 3);
    }

    private function resendDelaySeconds(): int
    {
        return $this->configInt('otp.resend_delay_seconds', 60);
    }

    private function maxRequestsPerHour(): int
    {
        return $this->configInt('otp.max_requests_per_hour', 5);
    }

    private function lockoutWindowHours(): int
    {
        return $this->configInt('otp.lockout_window_hours', 24);
    }

    /** @return non-empty-list<int> */
    private function lockoutSchedule(): array
    {
        $paliers = config('otp.lockout_minutes');
        $retenus = [];

        if (is_array($paliers)) {
            foreach ($paliers as $palier) {
                if (is_numeric($palier)) {
                    $retenus[] = (int) $palier;
                }
            }
        }

        return $retenus === [] ? [5, 15, 60, 1440] : $retenus;
    }

    private function configInt(string $cle, int $defaut): int
    {
        $valeur = config($cle, $defaut);

        return is_numeric($valeur) ? (int) $valeur : $defaut;
    }
}
