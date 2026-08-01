<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;

/**
 * Journal inaltérable à hash chaîné.
 *
 * record_hash = SHA-256( json_canonique( actor_type, actor_id, action,
 *                                        entity_type, entity_id, payload,
 *                                        created_at ) )
 * chain_hash  = SHA-256( prev_hash . record_hash )
 *
 * L'empreinte porte sur toutes les colonnes métier de la ligne, pas
 * seulement sur la charge utile : un accès direct à la base ne peut donc pas
 * réécrire « qui a fait quoi, sur quel bien, à quelle date » sans casser la
 * vérification (§4.5 de la spec, amendement du 01/08/2026).
 *
 * Règle non négociable : le payload entre dans l'empreinte sous sa forme
 * d'octets telle que stockée en colonne, jamais décodée puis ré-encodée — un
 * aller-retour par json_decode()/json_encode() rendrait l'empreinte
 * dépendante de la version de PHP et de la distinction objet/tableau. Même
 * règle pour `created_at` : sa représentation textuelle stockée, sans
 * reformatage.
 *
 * Le dernier chain_hash est lu sous verrou pour garantir la continuité même
 * sous écriture concurrente. Le verrou est court par construction : la
 * transaction ne contient qu'une lecture et une insertion (l'empreinte est
 * calculée avant l'ouverture de la transaction, et la relecture de la ligne
 * insérée se fait après sa fermeture). Ne jamais appeler append() depuis une
 * transaction englobante : cela allongerait la durée du verrou d'écriture
 * sur audit_log bien au-delà du temps d'une seule insertion.
 *
 * L'inaltérabilité ne repose pas uniquement sur cette classe : des
 * déclencheurs MariaDB (§4.6 de la spec) interdisent tout UPDATE/DELETE sur
 * audit_log au niveau du moteur, quel que soit le chemin de code emprunté.
 */
final class AuditChain
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function append(
        ActorType $actorType,
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        array $payload,
    ): AuditLog {
        $payloadJson = $this->canonicalJson($payload);
        $createdAt = now()->format('Y-m-d H:i:s');

        $columns = [
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payloadJson,
            'created_at' => $createdAt,
        ];

        $recordHash = $this->computeRecordHash($columns);

        // Sous écriture concurrente réelle, MariaDB peut détecter un
        // interblocage entre deux transactions qui se disputent le verrou de
        // lecture ci-dessous — y compris sur une table déjà peuplée, pas
        // seulement sur une table vide. Constaté expérimentalement
        // (tests/Feature/AuditChainConcurrencyTest.php) : sans réessai, la
        // plupart des append() lancés en parallèle échouaient en cascade ;
        // avec 5 à 10 essais, un échec résiduel subsistait encore environ une
        // fois sur dix sous forte contention (10 processus concurrents). La
        // transaction est courte (une lecture + une insertion), ce qui rend
        // un réessai sûr et peu coûteux : Laravel relance tout le bloc depuis
        // le début en cas d'interblocage. 25 essais se sont montrés stables
        // sur 25 exécutions consécutives du scénario le plus contentieux
        // pendant le développement de ce correctif.
        $id = DB::transaction(function () use ($columns, $recordHash): int {
            $previousChainHash = DB::table('audit_log')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('chain_hash');

            $prevHash = is_string($previousChainHash) ? $previousChainHash : self::GENESIS_HASH;
            $chainHash = hash('sha256', $prevHash.$recordHash);

            return DB::table('audit_log')->insertGetId([
                ...$columns,
                'record_hash' => $recordHash,
                'prev_hash' => $prevHash,
                'chain_hash' => $chainHash,
            ]);
        }, 25);

        return AuditLog::findOrFail($id);
    }

    /**
     * Rejoue la chaîne complète et signale la première entrée incohérente.
     *
     * Lit les colonnes brutes (pas les attributs castés par Eloquent) : le
     * payload doit être haché tel quel, jamais décodé puis ré-encodé.
     *
     * @return array{valid: bool, broken_at: int|null}
     */
    public function verify(): array
    {
        $prevHash = self::GENESIS_HASH;

        foreach (DB::table('audit_log')->orderBy('id')->cursor() as $row) {
            $row = (array) $row;

            $columns = [
                'actor_type' => $this->asStringColumn($row['actor_type'] ?? null),
                'actor_id' => $this->asNullableIntColumn($row['actor_id'] ?? null),
                'action' => $this->asStringColumn($row['action'] ?? null),
                'entity_type' => $this->asStringColumn($row['entity_type'] ?? null),
                'entity_id' => $this->asIntColumn($row['entity_id'] ?? null),
                'payload' => $this->asStringColumn($row['payload'] ?? null),
                'created_at' => $this->asStringColumn($row['created_at'] ?? null),
            ];

            $expectedRecordHash = $this->computeRecordHash($columns);
            $expectedChainHash = hash('sha256', $prevHash.$expectedRecordHash);

            $storedPrevHash = $this->asStringColumn($row['prev_hash'] ?? null);
            $storedRecordHash = $this->asStringColumn($row['record_hash'] ?? null);
            $storedChainHash = $this->asStringColumn($row['chain_hash'] ?? null);

            $intact = $storedPrevHash === $prevHash
                && $storedRecordHash === $expectedRecordHash
                && $storedChainHash === $expectedChainHash;

            if (! $intact) {
                return ['valid' => false, 'broken_at' => $this->asIntColumn($row['id'] ?? null)];
            }

            $prevHash = $storedChainHash;
        }

        return ['valid' => true, 'broken_at' => null];
    }

    /**
     * Coercions défensives des valeurs brutes lues depuis la table (leur
     * type PHP réel n'est pas garanti statiquement pour une ligne générique) :
     * une colonne qui ne contiendrait pas le scalaire attendu est traitée
     * comme vide/nulle plutôt que de provoquer une erreur de cast — ce qui
     * fait simplement échouer la comparaison d'empreinte, donc détecter une
     * rupture, au lieu de faire planter la vérification.
     */
    private function asStringColumn(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function asIntColumn(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    private function asNullableIntColumn(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (int) $value : null;
    }

    /**
     * @param  array{actor_type: string, actor_id: int|null, action: string, entity_type: string, entity_id: int, payload: string, created_at: string}  $columns
     *
     * @throws JsonException
     */
    private function computeRecordHash(array $columns): string
    {
        return hash('sha256', json_encode(
            $columns,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Sérialisation canonique du payload, produite une seule fois à
     * l'écriture : sans tri récursif des clés, deux payloads sémantiquement
     * identiques mais ordonnés différemment produiraient des octets, donc
     * des empreintes, différents.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->sortRecursive($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Trie récursivement les clés des tableaux associatifs (jamais les
     * listes, dont l'ordre porte du sens). Les objets (stdClass) sont
     * convertis en tableaux avant tri : sans cette conversion, un payload où
     * une valeur imbriquée est un objet plutôt qu'un tableau associatif
     * échapperait au tri et produirait une empreinte différente pour une
     * donnée pourtant sémantiquement identique.
     */
    private function sortRecursive(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
