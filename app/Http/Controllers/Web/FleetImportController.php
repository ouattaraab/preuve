<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserLeadsAFleet;
use App\Models\Company;
use App\Models\FleetImport;
use App\Models\User;
use App\Services\CompanyMemberService;
use App\Services\FleetService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * L'import d'un parc, depuis un fichier, sur le web (EP-07).
 *
 * POURQUOI CETTE PAGE EXISTE. L'application mobile le dit à ses utilisateurs,
 * mot pour mot : « L'import d'un parc entier se fait depuis un fichier, sur le
 * web ». Cette page n'existait pas. Le serveur savait pourtant importer depuis
 * le 3 août — découpage en tranches, réponse différée, suivi par identifiant —
 * et un loueur de quarante véhicules n'avait aucun moyen d'y accéder.
 *
 * ET C'EST BIEN SUR LE WEB QUE ÇA SE PASSE : un fichier de parc vit sur
 * l'ordinateur d'une agence, pas dans le téléphone du gérant. Demander de le
 * transférer d'abord serait une étape de plus pour rien.
 *
 * UN ESPACE DE CLIENT, PAS UN COIN DU BACK-OFFICE. Voir EnsureUserLeadsAFleet :
 * un loueur ne doit jamais atteindre la piste d'audit ni la modération.
 */
final class FleetImportController extends Controller
{
    public function __construct(
        private readonly FleetService $flotte,
        private readonly CompanyMemberService $membres,
    ) {}

    public function show(Request $request): View
    {
        $societes = EnsureUserLeadsAFleet::societesDe($this->membres, $this->utilisateur($request));

        return view('fleet.import', [
            'titre' => 'Importer un parc — Preuve',
            'societes' => $societes,
            // Suivis en cours, pour qu'un import lancé hier ne disparaisse pas
            // au rechargement de la page.
            'imports' => FleetImport::query()
                ->whereIn('company_id', array_map(
                    static fn (Company $societe): int => $societe->id,
                    $societes,
                ))
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'company' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:8192', 'mimes:csv,txt'],
        ]);

        $operateur = $this->utilisateur($request);
        $societe = $this->societeDe($request->integer('company'), $operateur);
        $fichier = $request->file('file');

        if (! $fichier instanceof UploadedFile) {
            return back()->withErrors(['file' => 'Le fichier n\'a pas pu être lu. Réessaie.']);
        }

        try {
            // LE MÊME SERVICE QUE L'API, et le même seuil. Deux chemins qui
            // importeraient différemment finiraient par diverger : une règle
            // corrigée d'un côté, oubliée de l'autre, et le loueur qui passe
            // du téléphone au web obtiendrait deux résultats pour un fichier.
            if ($this->flotte->countRows($fichier) <= FleetService::MAX_ROWS) {
                $rapport = $this->flotte->import($societe, $operateur, $fichier);

                return back()->with('rapport', $rapport)->with('societe', $societe->legal_name);
            }

            $import = $this->flotte->queueImport($societe, $operateur, $fichier);
        } catch (DomainException $e) {
            // Le motif vient du service : « colonne manquante », « au-delà de
            // dix mille lignes ». C'est ce qui permet de corriger le fichier
            // plutôt que de réessayer à l'identique.
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('fleet.import')->with('suivi', $import->id);
    }

    /** Avancement d'un import différé, rechargé par la page elle-même. */
    public function status(Request $request, int $import): View
    {
        $suivi = FleetImport::query()->whereKey($import)->first();

        if (! $suivi instanceof FleetImport) {
            abort(404);
        }

        // RATTACHÉ À SA SOCIÉTÉ : un identifiant d'import n'ouvre pas
        // l'inventaire d'un tiers, fût-ce en lecture d'un compteur.
        $this->societeDe($suivi->company_id, $this->utilisateur($request));

        return view('fleet.suivi', [
            'titre' => 'Import en cours — Preuve',
            'import' => $suivi,
        ]);
    }

    private function societeDe(int $company, User $operateur): Company
    {
        $societe = Company::query()->whereKey($company)->first();

        // 404 ET NON 403, comme l'API : distinguer « cette société n'existe
        // pas » de « elle ne vous appartient pas » permettrait de dénombrer
        // les sociétés du registre, une par une.
        if (! $societe instanceof Company || $this->membres->roleOf($societe, $operateur)?->value !== 'admin') {
            abort(404);
        }

        return $societe;
    }

    private function utilisateur(Request $request): User
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof User) {
            abort(401);
        }

        return $utilisateur;
    }
}
