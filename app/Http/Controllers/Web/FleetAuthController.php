<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Exceptions\OtpRefuseException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserLeadsAFleet;
use App\Models\User;
use App\Services\CompanyMemberService;
use App\Services\OtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Connexion des loueurs à l'espace web, par code à usage unique.
 *
 * AUCUN MOT DE PASSE, comme partout ailleurs dans PREUVE. Le même OtpService
 * que l'application et que le back-office : trois implémentations du même
 * geste finiraient par diverger, et c'est sur celle qu'on oublie que
 * l'expiration d'un code cesse un jour d'être vérifiée.
 *
 * LE RÔLE EST CONTRÔLÉ APRÈS LA PREUVE DE MAÎTRISE DU NUMÉRO, jamais avant :
 * refuser à l'émission révélerait quels numéros dirigent une flotte à qui les
 * essaie, un par un.
 */
final class FleetAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly CompanyMemberService $membres,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $compte = $request->user();

        if ($compte instanceof User && EnsureUserLeadsAFleet::societesDe($this->membres, $compte) !== []) {
            return redirect()->route('fleet.import');
        }

        return view('fleet.connexion', ['titre' => 'Espace loueur — Preuve']);
    }

    public function requestCode(Request $request): RedirectResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:150']]);

        // NUMÉRO OU ADRESSE. Le champ garde le nom `phone` — le renommer
        // casserait les formulaires en cache des navigateurs et les liens
        // enregistrés — mais il accepte les deux, et le libellé le dit.
        $destination = $this->otp->normalizeDestination($request->string('phone')->toString());
        $compte = $this->otp->isEmail($destination)
            ? User::where('email', $destination)->first()
            : User::where('phone', $destination)->first();

        // L'ADRESSE AU DOSSIER, jamais une adresse soumise : accepter une
        // adresse fournie permettrait de détourner le code de quelqu'un.
        if ($compte instanceof User) {
            $adresse = $compte->email;

            // Une ADRESSE s'auto-livre : elle est l'identité, et prouver
            // qu'on la lit prouve qu'on est le titulaire.
            if ($this->otp->isEmail($destination)) {
                $this->otp->request($destination, OtpPurpose::Login, OtpChannel::Email, $destination);
            } elseif ($this->canalCourriel() && is_string($adresse) && $adresse !== '') {
                $this->otp->request($destination, OtpPurpose::Login, OtpChannel::Email, $adresse);
            } else {
                $this->otp->request($destination, OtpPurpose::Login);
            }
        }

        // LA RÉPONSE EST LA MÊME que le numéro soit connu ou non : toute
        // différence observable ferait de cette page un annuaire des loueurs.
        return redirect()->route('fleet.login')
            ->with('etape', 'code')
            ->with('phone', $destination)
            ->with('message', 'Si ce compte peut recevoir un code, il vient de lui être envoyé.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $destination = $this->otp->normalizeDestination($request->string('phone')->toString());

        try {
            $this->otp->verify($destination, $request->string('code')->toString(), OtpPurpose::Login);
        } catch (OtpRefuseException $e) {
            // Rendu ICI et non par le gestionnaire global, qui répond en JSON :
            // un formulaire HTML ne sait pas l'afficher, et le refus
            // ressortirait en 500. Le loueur doit distinguer « code incorrect »
            // de « réessayez plus tard » pour ne pas s'acharner.
            return redirect()->route('fleet.login')
                ->with('etape', 'code')
                ->with('phone', $destination)
                ->withErrors(['code' => $e->getMessage()]);
        }

        $compte = $this->otp->isEmail($destination)
            ? User::where('email', $destination)->first()
            : User::where('phone', $destination)->first();

        if (! $compte instanceof User || EnsureUserLeadsAFleet::societesDe($this->membres, $compte) === []) {
            return redirect()->route('fleet.login')
                ->withErrors(['code' => 'Ce compte ne dirige aucune flotte. '
                    .'Demande à ton administrateur de t\'ajouter comme responsable.']);
        }

        Auth::login($compte, remember: false);

        // Renouvelée après authentification : sans cela, un identifiant de
        // session obtenu avant la connexion resterait valable après.
        $request->session()->regenerate();

        return redirect()->route('fleet.import');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('fleet.login');
    }

    private function canalCourriel(): bool
    {
        return config('preuve.sms.provider') === 'mail';
    }
}
