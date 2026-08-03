<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Coquille de la console d'administration.
 *
 * Les écrans ne rendent AUCUNE donnée côté serveur : ils sont peuplés par le
 * navigateur, qui interroge les mêmes `/api/v1/admin/*` que n'importe quel
 * client. Deux chemins de lecture — un pour l'API, un pour les gabarits —
 * finiraient par diverger, et l'écart ne se verrait qu'au moment d'une
 * décision d'agent.
 *
 * La navigation annonce les écrans à venir plutôt que de les masquer :
 * l'exploitant sait ce qui existe et ce qui arrive. En revanche elle CACHE ce
 * qu'un rôle n'atteint pas : afficher à un agent un écran que le middleware
 * lui refusera n'est pas de la transparence, c'est une promesse non tenue.
 */
final class AdminConsoleController extends Controller
{
    /**
     * Écrans de la console, dans l'ordre de la maquette.
     *
     * `disponible` dit si l'API qui l'alimente existe — le lot 1 ne sert que
     * ce qui est réellement branché.
     */
    private const ECRANS = [
        ['key' => 'overview', 'nom' => "Vue d'ensemble", 'route' => null, 'disponible' => false, 'admin_seul' => true],
        ['key' => 'moderation', 'nom' => 'Modération', 'route' => 'admin.moderation', 'disponible' => true, 'admin_seul' => false],
        ['key' => 'registry', 'nom' => 'Registre des biens', 'route' => 'admin.registry', 'disponible' => true, 'admin_seul' => false],
        ['key' => 'categories', 'nom' => 'Catégories & champs', 'route' => 'admin.categories', 'disponible' => true, 'admin_seul' => true],
        ['key' => 'users', 'nom' => 'Utilisateurs', 'route' => 'admin.users', 'disponible' => true, 'admin_seul' => false],
        ['key' => 'stats', 'nom' => 'Statistiques app', 'route' => null, 'disponible' => false, 'admin_seul' => true],
        ['key' => 'monitoring', 'nom' => 'Supervision', 'route' => 'admin.monitoring', 'disponible' => true, 'admin_seul' => false],
        ['key' => 'audit', 'nom' => "Piste d'audit", 'route' => 'admin.audit', 'disponible' => true, 'admin_seul' => true],
        ['key' => 'team', 'nom' => 'Équipe & rôles', 'route' => 'admin.team', 'disponible' => true, 'admin_seul' => true],
    ];

    public function moderation(Request $request): View
    {
        return view('admin.moderation', $this->contexte($request, 'moderation', 'Modération'));
    }

    public function registry(Request $request): View
    {
        return view('admin.registry', $this->contexte($request, 'registry', 'Registre des biens'));
    }

    public function users(Request $request): View
    {
        return view('admin.users', $this->contexte($request, 'users', 'Utilisateurs'));
    }

    public function monitoring(Request $request): View
    {
        return view('admin.monitoring', $this->contexte($request, 'monitoring', 'Supervision'));
    }

    public function categories(Request $request): View
    {
        return view('admin.categories', $this->contexte($request, 'categories', 'Catégories & champs'));
    }

    public function audit(Request $request): View
    {
        return view('admin.audit', $this->contexte($request, 'audit', "Piste d'audit"));
    }

    public function team(Request $request): View
    {
        return view('admin.team', $this->contexte($request, 'team', 'Équipe & rôles'));
    }

    /** @return array<string, mixed> */
    private function contexte(Request $request, string $vue, string $titre): array
    {
        $utilisateur = $request->user();
        $nom = $utilisateur instanceof User ? ($utilisateur->full_name ?? $utilisateur->phone) : '—';
        $estAdministrateur = $utilisateur instanceof User && $utilisateur->role === UserRole::Admin;

        return [
            'vue' => $vue,
            'titre' => $titre,
            // Exposé aux gabarits pour NE PAS MONTRER la levée d'anonymat à un
            // agent. Ce n'est pas le garde — il est sur la route et dans le
            // contrôleur d'API — c'est ce qui évite d'apprendre à un agent
            // qu'une porte existe et qu'elle lui est fermée.
            'estAdministrateur' => $estAdministrateur,
            'navigation' => $this->navigation($estAdministrateur),
            'nomAffiche' => $nom,
            'initiales' => $this->initiales($nom),
            'profil' => $utilisateur instanceof User ? $utilisateur->role->label() : '—',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function navigation(bool $estAdministrateur): array
    {
        $items = [];

        foreach (self::ECRANS as $ecran) {
            // Un agent ne voit pas les écrans de configuration : le lien
            // mènerait à un 403, et l'écran existerait dans sa tête sans
            // exister pour lui.
            if ($ecran['admin_seul'] && ! $estAdministrateur) {
                continue;
            }

            $items[] = [
                ...$ecran,
                'url' => $ecran['route'] === null ? null : route($ecran['route']),
            ];
        }

        return $items;
    }

    private function initiales(string $nom): string
    {
        $mots = preg_split('/\s+/', trim($nom)) ?: [];
        $lettres = '';

        foreach (array_slice($mots, 0, 2) as $mot) {
            $lettres .= mb_strtoupper(mb_substr((string) $mot, 0, 1));
        }

        return $lettres === '' ? '—' : $lettres;
    }
}
