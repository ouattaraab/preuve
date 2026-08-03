<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Exceptions\OtpRefuseException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\ConfigurableOtpSender;
use App\Services\OtpService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Connexion à l'espace administrateur.
 *
 * MÊME PARCOURS QUE LES UTILISATEURS : téléphone puis code à usage unique. La
 * maquette prévoyait un mot de passe accompagné d'un second facteur ; la
 * plateforme n'a aucun mot de passe, et en introduire pour les comptes qui
 * voient les pièces d'identité et tranchent les litiges reviendrait à créer la
 * seule chose qu'elle avait éliminée — un secret à stocker, à réinitialiser et
 * à faire fuiter. Le code à usage unique EST le second facteur.
 *
 * SESSION, PAS JETON. La console affiche des cartes grises et des CNI : un
 * jeton rangé dans le navigateur serait lisible par la première faille XSS. Le
 * cookie de session est `httpOnly`.
 *
 * LE RÔLE EST VÉRIFIÉ À LA VÉRIFICATION DU CODE, jamais à l'émission : refuser
 * plus tôt apprendrait, par la seule différence de réponse, quels numéros sont
 * ceux d'administrateurs.
 */
final class AdminAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SettingsRepository $settings,
    ) {}

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.moderation');
        }

        return view('admin.login');
    }

    /** Émet un code. La réponse ne dit jamais si le compte existe. */
    public function requestCode(Request $request): RedirectResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);

        $telephone = $this->otp->normalizeDestination($request->string('phone')->toString());
        $compte = User::where('phone', $telephone)->first();

        // Livraison : l'adresse AU DOSSIER, jamais une adresse soumise. Voir
        // OtpAuthController — accepter une adresse fournie permettrait de
        // détourner le code d'un administrateur.
        if ($compte instanceof User && $this->canalCourriel()) {
            $adresse = $compte->email;

            if (is_string($adresse) && $adresse !== '') {
                $this->otp->request($telephone, OtpPurpose::Login, OtpChannel::Email, $adresse);
            }
        } elseif ($compte instanceof User) {
            $this->otp->request($telephone, OtpPurpose::Login);
        }

        return redirect()->route('admin.login')
            ->with('etape', 'code')
            ->with('phone', $telephone)
            ->with('message', 'Si ce compte peut recevoir un code, il vient de lui être envoyé.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $telephone = $this->otp->normalizeDestination($request->string('phone')->toString());

        try {
            $this->otp->verify($telephone, $request->string('code')->toString(), OtpPurpose::Login);
        } catch (OtpRefuseException $e) {
            // Le refus est rendu ICI et non par le gestionnaire global : celui-ci
            // répond en JSON, ce qu'un formulaire HTML ne sait pas afficher — et
            // le refus ressortait en 500. L'agent doit lire ce qui s'est passé,
            // et distinguer « code incorrect » de « réessayez plus tard » pour
            // ne pas s'acharner.
            return redirect()->route('admin.login')
                ->with('etape', 'code')
                ->with('phone', $telephone)
                ->withErrors(['code' => $e->getMessage()]);
        }

        $compte = User::where('phone', $telephone)->first();

        // Le contrôle du rôle a lieu ICI, une fois la maîtrise du numéro (ou de
        // la boîte) prouvée : refuser à l'émission révélerait quels numéros
        // sont administrateurs à qui les essaie.
        if (! $compte instanceof User || ! $this->peutEntrer($compte)) {
            return redirect()->route('admin.login')
                ->withErrors(['code' => "Ce compte n'a pas accès à l'espace d'administration."]);
        }

        Auth::login($compte, remember: false);

        // Renouvelée après authentification : sans cela, un identifiant de
        // session obtenu avant la connexion resterait valable après.
        $request->session()->regenerate();

        return redirect()->route('admin.moderation');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function peutEntrer(User $compte): bool
    {
        return in_array($compte->role->value, ['admin', 'agent'], true);
    }

    private function canalCourriel(): bool
    {
        return $this->settings->get(ConfigurableOtpSender::PROVIDER_KEY) === 'mail';
    }
}
