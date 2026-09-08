<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adresse de contact pour l'exercice des droits (Loi 2013-450).
 *
 * ELLE EST UN RÉGLAGE ET NON UNE CONSTANTE DU CODE : une adresse de contact
 * change — une personne part, un service se réorganise — et la faire vivre dans
 * un fichier obligerait à livrer une version du serveur pour corriger une ligne
 * que la loi impose d'afficher.
 *
 * TANT QU'ELLE N'EST PAS RÉGLÉE, LA PAGE PUBLIQUE LE DIT. Afficher une adresse
 * inventée ou celle de l'expéditeur automatique serait pire que de n'en afficher
 * aucune : la personne qui écrit pour faire effacer ses données croirait avoir
 * saisi le responsable, et son silence passerait pour un refus.
 */
final class LegalContactController extends Controller
{
    public const SETTING = 'legal.contact_email';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditChain $auditChain,
    ) {}

    public function show(): JsonResponse
    {
        $adresse = $this->adresse();

        return response()->json([
            'contact' => $adresse,
            'configured' => $adresse !== null,
            'note' => $adresse === null
                ? 'Aucune adresse réglée : la page publique annonce que le point de contact est '.
                  'en cours d\'ouverture, plutôt que d\'afficher une adresse qui ne répondrait pas.'
                : 'Cette adresse est publiée sur la page de confidentialité. Elle doit recevoir des '.
                  'demandes d\'accès, de rectification et d\'effacement, et y répondre.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'contact' => ['sometimes', 'nullable', 'string', 'max:255', 'email'],
        ]);

        $administrateur = $request->user();
        $administrateurId = $administrateur instanceof User ? $administrateur->id : null;

        // C'est la PRÉSENCE du champ qui distingue « effacer » de « ne pas
        // toucher » : une chaîne vide convertie en null rendrait les deux
        // indiscernables.
        if (! $request->has('contact')) {
            return response()->json(['message' => 'Aucun changement demandé.'], 422);
        }

        $ancienne = $this->adresse();
        $nouvelle = $request->string('contact')->toString();
        $nouvelle = $nouvelle === '' ? null : $nouvelle;

        // Journalisé SANS les adresses : détourner le point de contact des
        // droits est une manière discrète d'empêcher leur exercice, et le
        // savoir suffit à le contester. Les adresses elles-mêmes n'ont rien à
        // faire dans une chaîne inaltérable.
        $this->auditChain->append(
            ActorType::Agent,
            $administrateurId,
            'admin.legal_contact_changed',
            'setting',
            0,
            ['was_set' => $ancienne !== null, 'now_set' => $nouvelle !== null],
        );

        $this->settings->set(self::SETTING, $nouvelle, $administrateurId);

        return response()->json([
            'message' => $nouvelle === null
                ? 'Adresse retirée. La page publique annonce désormais un point de contact en cours d\'ouverture.'
                : 'Adresse publiée sur la page de confidentialité.',
            'contact' => $nouvelle,
        ]);
    }

    private function adresse(): ?string
    {
        $valeur = $this->settings->get(self::SETTING);

        return is_string($valeur) && $valeur !== '' ? $valeur : null;
    }
}
