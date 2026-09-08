<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Levée d'anonymat sur réquisition (Loi 2013-450).
 *
 * ELLE EXISTE PARCE QUE L'ALTERNATIVE EST PIRE. Une réquisition judiciaire
 * arrivera ; sans chemin prévu, l'exploitant y répondra par une requête SQL
 * directe — sans fondement consigné, sans trace, sans registre. Le risque n'est
 * pas de construire cette capacité : il est de la laisser s'improviser.
 *
 * ELLE EST DÉLIBÉRÉMENT PLUS DIFFICILE QUE TOUT LE RESTE :
 *
 * 1. RÉSERVÉE AUX ADMINISTRATEURS. Un agent instruit des dossiers, il ne lève
 *    pas l'anonymat. Le rôle qui modère n'est pas celui qui répond à un juge.
 * 2. UN FONDEMENT STRUCTURÉ ET OBLIGATOIRE — autorité, référence, date, objet.
 *    Un champ libre unique se remplirait de « enquête » et ne prouverait rien.
 * 3. UNE PERSONNE À LA FOIS. Aucun listage, aucun export : la divulgation vise
 *    un sujet nommé, jamais une population.
 * 4. AUCUN ÉTAT « DÉVERROUILLÉ ». La réponse est rendue une fois ; la seconde
 *    d'après, l'identité est de nouveau inaccessible. Un droit qui dure est un
 *    droit qu'on oublie d'éteindre.
 * 5. DOUBLE TRACE INEFFAÇABLE : la chaîne d'audit et un registre dédié, tous
 *    deux append-only. C'est ce registre qu'une autorité de protection des
 *    données demanderait à voir.
 *
 * LE SUJET N'EST PAS PRÉVENU, et ce silence est une décision, pas un oubli :
 * une réquisition s'accompagne le plus souvent d'une obligation de
 * confidentialité, et prévenir reviendrait à instruire la personne visée. La
 * contrepartie est le registre : ce qui n'est pas dit sur le moment reste
 * établi pour toujours.
 */
final class IdentityDisclosureController extends Controller
{
    public function __construct(private readonly AuditChain $auditChain) {}

    /**
     * Divulgue l'identité d'un sujet, une fois, contre un fondement légal.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            // Chaque champ est exigé séparément : c'est ce qui distingue un
            // fondement d'un prétexte.
            'authority' => ['required', 'string', 'min:3', 'max:160'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'purpose' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        $demandeur = $request->user();

        // Un agent instruit des dossiers ; il ne répond pas à un juge. Le
        // contrôle est refait ici et ne s'en remet pas au seul routage :
        // c'est l'opération la plus sensible de la plateforme.
        if (! $demandeur instanceof User || $demandeur->role !== UserRole::Admin) {
            abort(403, "Seul un administrateur peut lever l'anonymat.");
        }

        $sujet = User::find((int) $request->integer('user_id'));

        if (! $sujet instanceof User) {
            abort(404);
        }

        $autorite = $request->string('authority')->toString();
        $reference = $request->string('reference')->toString();
        $objet = $request->string('purpose')->toString();

        // La chaîne d'audit consigne le FAIT et son fondement, jamais les
        // données divulguées : elle est inaltérable et survivrait à tout
        // exercice du droit à l'effacement.
        $this->auditChain->append(
            ActorType::Agent,
            $demandeur->id,
            'admin.identity_disclosed',
            'user',
            $sujet->id,
            [
                'authority' => $autorite,
                'reference' => $reference,
                'issued_on' => $request->date('issued_on')?->format('Y-m-d'),
                'purpose' => $objet,
            ],
        );

        DB::table('identity_disclosures')->insert([
            'subject_user_id' => $sujet->id,
            'requested_by' => $demandeur->id,
            'authority' => $autorite,
            'reference' => $reference,
            'issued_on' => $request->date('issued_on')?->format('Y-m-d'),
            'purpose' => $objet,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'disclosed_at' => now()->toIso8601String(),
            'identity' => [
                'id' => $sujet->id,
                'full_name' => $sujet->full_name,
                'phone' => $sujet->phone,
                'email' => $sujet->email,
                'account_status' => $sujet->getAttribute('status'),
                'kyc_status' => $sujet->kycStatus()->value,
                'registered_at' => $sujet->created_at?->toIso8601String(),
                // Les biens rattachés : c'est le plus souvent l'objet même de
                // la réquisition. Les identifiants seulement, sans reprendre
                // les pièces justificatives.
                'assets' => Asset::where('owner_user_id', $sujet->id)
                    ->whereNotNull('active_flag')
                    ->get(['public_ref', 'identifier_normalized', 'life_status'])
                    ->map(fn (Asset $bien): array => [
                        'public_ref' => $bien->public_ref,
                        'identifier' => $bien->identifier_normalized,
                        'life_status' => $bien->life_status->value,
                    ])->all(),
                // Le numéro de pièce d'identité n'est stocké qu'en empreinte et
                // n'est donc pas restituable : le dire évite qu'on le cherche.
                'id_number' => null,
                'id_number_note' => 'Le numéro de pièce n\'est conservé qu\'en empreinte SHA-256 '.
                    '(Loi 2013-450) : il n\'est pas restituable, y compris sur réquisition.',
            ],
            'notice' => 'Cette divulgation est consignée dans la chaîne d\'audit et au registre des '.
                'levées d\'anonymat, tous deux inaltérables. Aucun accès durable n\'a été ouvert : '.
                'une nouvelle réquisition sera nécessaire.',
        ]);
    }

    /**
     * Registre des divulgations passées.
     *
     * Lisible par les administrateurs sans réquisition : il ne contient aucune
     * donnée personnelle du sujet — seulement qui a demandé quoi, quand et en
     * vertu de quoi. C'est le document de reddition de comptes, et il ne sert
     * à rien s'il faut une procédure pour le consulter.
     */
    public function index(Request $request): JsonResponse
    {
        $demandeur = $request->user();

        if (! $demandeur instanceof User || $demandeur->role !== UserRole::Admin) {
            abort(403);
        }

        $lignes = DB::table('identity_disclosures')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'disclosures' => $lignes->map(fn (object $l): array => [
                'id' => $l->id,
                'subject_user_id' => $l->subject_user_id,
                'requested_by' => $l->requested_by,
                'authority' => $l->authority,
                'reference' => $l->reference,
                'issued_on' => $l->issued_on,
                'purpose' => $l->purpose,
                'disclosed_at' => $l->created_at,
            ])->all(),
            'notice' => 'Registre append-only. Il ne contient aucune coordonnée : seulement qui a '.
                'levé l\'anonymat de qui, quand, et sur quel fondement.',
        ]);
    }
}
