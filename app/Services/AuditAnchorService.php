<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditAnchor;
use App\Services\Audit\AnchorChannel;
use App\Services\Audit\AnchorDocument;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ancrage externe du hash de tête de la chaîne d'audit (systemPatterns §6).
 *
 * SANS ANCRAGE, LA CHAÎNE N'EST PAS OPPOSABLE. Son algorithme est public et ne
 * comporte aucun secret : quiconque dispose du droit INSERT sur la base peut
 * reconstruire de bout en bout une chaîne parfaitement cohérente, dans laquelle
 * une action gênante n'aura jamais existé. La vérification interne
 * (AuditChain::verify) ne détecte que les réécritures maladroites, jamais une
 * reconstruction complète.
 *
 * Ce qui rend la reconstruction détectable, c'est qu'une empreinte de la tête a
 * été publiée AILLEURS, à une date : pour la falsifier, il faudrait aussi
 * compromettre le tiers qui la conserve.
 *
 * Deux conséquences gouvernent ce service :
 *
 * 1. UN ANCRAGE N'EST RÉUSSI QUE S'IL A QUITTÉ LA PLATEFORME. Si aucun canal
 *    externe n'aboutit, l'ancrage est consigné en échec — jamais présenté comme
 *    fait. Se croire protégé sans l'être est pire que savoir qu'on ne l'est
 *    pas : c'est ce qui ferait affirmer devant un tribunal une opposabilité qui
 *    n'existe pas.
 * 2. UN ÉCHEC DOIT SE VOIR. La commande sort en erreur, ce qui remonte par le
 *    planificateur, et le registre garde la trace du motif.
 */
final class AuditAnchorService
{
    /** @param list<AnchorChannel> $channels */
    public function __construct(private readonly array $channels) {}

    /**
     * Publie l'état courant de la chaîne vers tous les canaux configurés.
     *
     * Ancre MÊME si rien n'a été ajouté depuis la veille : un ancrage identique
     * au précédent atteste qu'aucune entrée n'a été insérée entre les deux
     * dates, ce qui est en soi une information — une chaîne qui aurait été
     * reconstruite plus courte se trahirait par un compteur en baisse.
     */
    public function anchor(): AuditAnchor
    {
        $tete = DB::table('audit_log')->orderByDesc('id')->first();
        $total = DB::table('audit_log')->count();

        $identifiant = $tete === null ? null : $tete->id;
        $empreinte = $tete === null ? null : $tete->chain_hash;
        $nom = config('app.name');

        $document = new AnchorDocument(
            anchoredAt: now(),
            headId: is_numeric($identifiant) ? (int) $identifiant : null,
            // Une chaîne vide s'ancre sur une empreinte nulle : c'est une
            // valeur légitime, pas une absence de données.
            headChainHash: is_string($empreinte) ? $empreinte : str_repeat('0', 64),
            entryCount: $total,
            application: is_string($nom) ? $nom : 'PREUVE',
        );

        $resultats = [];
        $auMoinsUnCanalExterne = false;

        foreach ($this->channels as $canal) {
            if (! $canal->isConfigured()) {
                $resultats[$canal->name()] = ['status' => 'not_configured'];

                continue;
            }

            try {
                $canal->publish($document);
                $resultats[$canal->name()] = ['status' => 'published'];
                $auMoinsUnCanalExterne = true;
            } catch (Throwable $e) {
                // Le motif est consigné, mais jamais le message brut d'une
                // exception réseau, qui peut contenir des identifiants.
                $resultats[$canal->name()] = ['status' => 'failed', 'error' => $e::class];
            }
        }

        return AuditAnchor::create([
            'head_id' => $document->headId,
            'head_chain_hash' => $document->headChainHash,
            'entry_count' => $document->entryCount,
            'channels' => $resultats,
            'status' => $auMoinsUnCanalExterne ? 'anchored' : 'failed',
            'created_at' => $document->anchoredAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Confronte la chaîne actuelle aux ancrages déjà publiés.
     *
     * C'est ici que se détecte la falsification que la vérification interne ne
     * voit pas : une chaîne reconstruite est cohérente avec elle-même, mais son
     * empreinte de tête à une date passée ne peut plus coïncider avec celle qui
     * a été publiée ce jour-là.
     *
     * @return array{valid: bool, checked: int, breaches: list<array{anchor_id: int, anchored_at: string, reason: string}>}
     */
    public function verifyAgainstAnchors(): array
    {
        $ruptures = [];
        $verifies = 0;

        foreach (AuditAnchor::where('status', 'anchored')->orderBy('id')->cursor() as $ancrage) {
            $verifies++;

            if ($ancrage->head_id === null) {
                // Chaîne vide à l'époque : rien à confronter, mais le compteur
                // d'alors doit rester un minorant.
                if (DB::table('audit_log')->count() < $ancrage->entry_count) {
                    $ruptures[] = $this->rupture($ancrage, 'Des entrées ont disparu depuis cet ancrage.');
                }

                continue;
            }

            $entree = DB::table('audit_log')->where('id', $ancrage->head_id)->first();

            if ($entree === null) {
                $ruptures[] = $this->rupture(
                    $ancrage,
                    "L'entrée de tête ancrée (#{$ancrage->head_id}) n'existe plus."
                );

                continue;
            }

            if ($entree->chain_hash !== $ancrage->head_chain_hash) {
                $ruptures[] = $this->rupture(
                    $ancrage,
                    "L'empreinte de l'entrée #{$ancrage->head_id} ne correspond plus à celle publiée : ".
                    'la chaîne a été réécrite après cet ancrage.'
                );

                continue;
            }

            // Le journal ne fait que croître : un compteur en baisse signale
            // une suppression, que les déclencheurs interdisent — donc un
            // contournement de la base elle-même.
            if (DB::table('audit_log')->count() < $ancrage->entry_count) {
                $ruptures[] = $this->rupture($ancrage, 'Des entrées ont disparu depuis cet ancrage.');
            }
        }

        return ['valid' => $ruptures === [], 'checked' => $verifies, 'breaches' => $ruptures];
    }

    /** Dernier ancrage réussi, pour savoir depuis quand la chaîne est opposable. */
    public function lastSuccessful(): ?AuditAnchor
    {
        return AuditAnchor::where('status', 'anchored')->orderByDesc('id')->first();
    }

    /**
     * @return array{anchor_id: int, anchored_at: string, reason: string}
     */
    private function rupture(AuditAnchor $ancrage, string $motif): array
    {
        return [
            'anchor_id' => $ancrage->id,
            'anchored_at' => $ancrage->created_at->toIso8601String(),
            'reason' => $motif,
        ];
    }
}
