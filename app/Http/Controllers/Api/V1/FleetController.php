<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyMemberService;
use App\Services\FleetService;
use App\Services\QuotaService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Offre flotte B2B (ST-0701 à ST-0703) et délégation (ST-0705).
 *
 * Le représentant légal et ses collaborateurs habilités agissent sur la flotte.
 * La gestion des accès, elle, reste aux administrateurs de flotte : un
 * opérateur de comptoir marque des véhicules, il n'invite personne.
 */
final class FleetController extends Controller
{
    public function __construct(
        private readonly FleetService $flotte,
        private readonly QuotaService $quotas,
        private readonly CompanyMemberService $membres,
    ) {}

    public function dashboard(Request $request, int $company): JsonResponse
    {
        $societe = $this->societe($request, $company);

        return response()->json([
            'dashboard' => $this->flotte->dashboard($societe),
            'billing' => $this->quotas->forCompany($societe),
        ]);
    }

    public function import(Request $request, int $company): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $societe = $this->societe($request, $company);
        $fichier = $request->file('file');

        if (! $fichier instanceof UploadedFile) {
            abort(422);
        }

        try {
            $rapport = $this->flotte->import($societe, $this->utilisateur($request), $fichier);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json([
            // Le rapport d'erreurs est rendu ligne à ligne : le loueur corrige
            // son fichier et réimporte, sans que les véhicules déjà entrés ne
            // soient recréés.
            'report' => $rapport,
            'message' => $rapport['truncated']
                ? 'Import partiel : seules les '.FleetService::MAX_ROWS.' premières lignes ont été traitées. '
                    .'Réimportez le reste du fichier.'
                : 'Import terminé.',
        ]);
    }

    public function markRented(Request $request, int $company): JsonResponse
    {
        $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer'],
            'rented' => ['required', 'boolean'],
        ]);

        $societe = $this->societe($request, $company);

        $identifiants = [];

        foreach ($request->array('asset_ids') as $valeur) {
            if (is_numeric($valeur)) {
                $identifiants[] = (int) $valeur;
            }
        }

        $rapport = $this->flotte->markRented(
            $societe,
            $this->utilisateur($request),
            $identifiants,
            $request->boolean('rented'),
        );

        return response()->json(['report' => $rapport]);
    }

    /** Liste les accès délégués (ST-0705). */
    public function members(Request $request, int $company): JsonResponse
    {
        $societe = $this->societe($request, $company);

        return response()->json(['members' => $this->membres->members($societe)]);
    }

    public function invite(Request $request, int $company): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', Rule::enum(CompanyRole::class)],
        ]);

        $societe = $this->societe($request, $company);

        try {
            $membre = $this->membres->invite(
                $societe,
                $this->utilisateur($request),
                $request->string('phone')->toString(),
                CompanyRole::from($request->string('role')->toString()),
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return response()->json([
            'member' => ['id' => $membre->id, 'role' => $membre->role->value],
        ], 201);
    }

    public function revoke(Request $request, int $company, int $member): JsonResponse
    {
        $societe = $this->societe($request, $company);

        try {
            $retire = $this->membres->revoke($societe, $this->utilisateur($request), $member);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['member' => $e->getMessage()]);
        }

        if (! $retire) {
            abort(404);
        }

        return response()->json(['message' => 'Accès révoqué.']);
    }

    /**
     * Société sur laquelle l'utilisateur a un accès — comme représentant légal
     * ou comme collaborateur habilité.
     *
     * 404 plutôt que 403 sur la société d'un tiers : confirmer son existence
     * par son identifiant interne donnerait un moyen de balayage.
     */
    private function societe(Request $request, int $company): Company
    {
        $societe = Company::query()->whereKey($company)->first();

        if (! $societe instanceof Company) {
            abort(404);
        }

        if ($this->membres->roleOf($societe, $this->utilisateur($request)) === null) {
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
