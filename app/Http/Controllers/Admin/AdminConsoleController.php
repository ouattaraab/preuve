<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
 * l'exploitant sait ce qui existe et ce qui arrive.
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
        ['key' => 'overview', 'nom' => "Vue d'ensemble", 'route' => null, 'disponible' => false],
        ['key' => 'moderation', 'nom' => 'Modération', 'route' => 'admin.moderation', 'disponible' => true],
        ['key' => 'registry', 'nom' => 'Registre des biens', 'route' => null, 'disponible' => false],
        ['key' => 'categories', 'nom' => 'Catégories & champs', 'route' => null, 'disponible' => false],
        ['key' => 'users', 'nom' => 'Utilisateurs', 'route' => null, 'disponible' => false],
        ['key' => 'stats', 'nom' => 'Statistiques app', 'route' => null, 'disponible' => false],
        ['key' => 'monitoring', 'nom' => 'Supervision', 'route' => 'admin.monitoring', 'disponible' => true],
        ['key' => 'audit', 'nom' => "Piste d'audit", 'route' => null, 'disponible' => false],
        ['key' => 'team', 'nom' => 'Équipe & rôles', 'route' => null, 'disponible' => false],
    ];

    public function moderation(Request $request): View
    {
        return view('admin.moderation', $this->contexte($request, 'moderation', 'Modération'));
    }

    public function monitoring(Request $request): View
    {
        return view('admin.monitoring', $this->contexte($request, 'monitoring', 'Supervision'));
    }

    /** @return array<string, mixed> */
    private function contexte(Request $request, string $vue, string $titre): array
    {
        $utilisateur = $request->user();
        $nom = $utilisateur instanceof User ? ($utilisateur->full_name ?? $utilisateur->phone) : '—';

        return [
            'vue' => $vue,
            'titre' => $titre,
            'navigation' => $this->navigation(),
            'nomAffiche' => $nom,
            'initiales' => $this->initiales($nom),
            'profil' => $utilisateur instanceof User ? $utilisateur->role->label() : '—',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function navigation(): array
    {
        $items = [];

        foreach (self::ECRANS as $ecran) {
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
