<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Équipe et habilitations (ST-0902).
 *
 * LES PAGES VISIBLES SONT DÉDUITES DU RÔLE, ELLES NE SONT PAS STOCKÉES. La
 * maquette montrait quatre profils avec des pages cochables ; la plateforme en
 * a trois, et ses accès sont tenus par des middlewares présents sur chaque
 * route. Ajouter une table d'habilitations créerait une SECONDE source de
 * vérité à côté de la première : le jour où elles divergeraient, l'écran
 * afficherait un droit que le middleware refuse — ou pire, l'inverse. Ce que
 * cet écran montre est donc ce que le code applique réellement.
 *
 * PROMOUVOIR EST L'OPÉRATION LA PLUS DANGEREUSE de cette console : elle
 * fabrique les comptes qui voient les pièces d'identité et lèvent l'anonymat.
 * Réservée aux administrateurs, journalisée avec l'ancien et le nouveau rôle —
 * savoir qu'un changement a eu lieu ne suffit pas à juger s'il a élargi ou
 * restreint.
 */
final class TeamController extends Controller
{
    /**
     * Ce que chaque rôle atteint réellement, tel que les middlewares
     * l'appliquent. Cette table DÉCRIT le code, elle ne le gouverne pas.
     *
     * @var array<string, list<string>>
     */
    private const PAGES = [
        'admin' => [
            "Vue d'ensemble", 'Modération', 'Registre des biens', 'Catégories & champs',
            'Utilisateurs', 'Statistiques app', 'Supervision', "Piste d'audit", 'Équipe & rôles',
        ],
        'agent' => ['Modération', 'Registre des biens', 'Utilisateurs', 'Supervision'],
        'user' => [],
    ];

    public function __construct(private readonly AuditChain $auditChain) {}

    public function index(): JsonResponse
    {
        $membres = User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Agent->value])
            ->orderBy('id')
            ->get();

        return response()->json([
            'members' => $membres->map(fn (User $m): array => [
                'id' => $m->id,
                'name' => $m->full_name,
                // L'adresse est montrée ICI, contrairement à l'annuaire des
                // abonnés : ce sont des collègues, pas des administrés, et
                // c'est par elle qu'ils reçoivent leur code de connexion.
                'email' => $m->email,
                'role' => $m->role->value,
                'role_label' => $m->role->label(),
                'pages' => self::PAGES[$m->role->value],
                'status' => $m->getAttribute('status'),
            ])->all(),
            'roles' => [
                ['value' => 'agent', 'label' => 'Agent', 'pages' => self::PAGES['agent']],
                ['value' => 'admin', 'label' => 'Administrateur', 'pages' => self::PAGES['admin']],
            ],
            'notice' => 'Les pages visibles découlent du rôle et ne se règlent pas une par une : '.
                'ce que cet écran montre est ce que le code applique.',
        ]);
    }

    public function setRole(Request $request, int $user): JsonResponse
    {
        $request->validate([
            'role' => ['required', Rule::in(['user', 'agent', 'admin'])],
            'reason' => ['required', 'string', 'min:5', 'max:280'],
        ]);

        $auteur = $request->user();

        // Réservé aux administrateurs, et le contrôle est refait ici : c'est
        // l'opération qui fabrique les comptes capables de lever l'anonymat.
        if (! $auteur instanceof User || $auteur->role !== UserRole::Admin) {
            abort(403, 'Seul un administrateur peut modifier les habilitations.');
        }

        $membre = User::find($user);

        if (! $membre instanceof User) {
            abort(404);
        }

        // Un administrateur ne se rétrograde pas lui-même : sur une
        // installation qui n'a qu'un compte, plus personne ne pourrait rendre
        // le droit — y compris à lui.
        if ($auteur->id === $membre->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier votre propre habilitation.',
            ], 422);
        }

        $ancien = $membre->role;
        $nouveau = UserRole::from($request->string('role')->toString());

        if ($ancien === $nouveau) {
            return response()->json(['message' => 'Ce compte a déjà cette habilitation.'], 422);
        }

        $this->auditChain->append(
            ActorType::Agent,
            $auteur->id,
            'admin.role_changed',
            'user',
            $membre->id,
            // Les DEUX rôles : savoir qu'un changement a eu lieu ne suffit pas
            // à juger s'il a élargi ou restreint l'accès.
            ['from' => $ancien->value, 'to' => $nouveau->value, 'reason' => $request->string('reason')->toString()],
        );

        $membre->forceFill(['role' => $nouveau])->save();

        // Rétrograder ne suffit pas : sans révocation, l'ancien droit vivrait
        // jusqu'à l'expiration de la session en cours.
        if (! $nouveau->hasBackOfficeAccess()) {
            $membre->tokens()->delete();
        }

        return response()->json([
            'message' => sprintf('Habilitation modifiée : %s → %s.', $ancien->label(), $nouveau->label()),
            'role' => $nouveau->value,
            'pages' => self::PAGES[$nouveau->value],
        ]);
    }
}
