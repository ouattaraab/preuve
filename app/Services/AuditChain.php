<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
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
 * reformatage — colonne DATETIME (pas TIMESTAMP, voir SchemaSocleTest pour
 * l'assertion qui verrouille ce type) et connexion `mariadb` épinglée sur
 * +00:00 (config/database.php), pour que cette représentation ne dépende
 * jamais du fuseau de la session qui écrit ou qui vérifie.
 *
 * Les écritures sont sérialisées par un verrou nommé MariaDB (GET_LOCK, voir
 * lockName()) plutôt que par une reprise sur interblocage : le motif
 * "ORDER BY id DESC LIMIT 1 ... FOR UPDATE" verrouille une cible mobile, ce
 * qui produit un interblocage garanti entre écritures concurrentes plutôt
 * qu'une simple contention absorbable par des essais supplémentaires (mesuré
 * lors de la correction de ce défaut — voir task-5-report.md). Le nom du
 * verrou est suffixé par le nom de la base de connexion : un nom global au
 * serveur MariaDB affamerait deux bases distinctes du même serveur l'une
 * l'autre (ex. `preuve` et `preuve_staging` sur le même mutualisé).
 *
 * Deux méthodes publiques écrivent dans la chaîne, chacune pour un usage
 * distinct :
 * - append() : le verrou est pris, une transaction courte (une lecture, une
 *   insertion) s'exécute, le verrou est relâché. Elle refuse de s'exécuter
 *   depuis une transaction déjà ouverte : sur ce chemin, le COMMIT de
 *   l'appelant aurait lieu après la libération du verrou, ce qui viderait la
 *   sérialisation de son effet — une revue l'a démontré avec des entrées
 *   d'audit perdues sous écriture concurrente réelle.
 * - transaction() : pour le cas où l'action métier elle-même doit être
 *   transactionnelle avec son entrée d'audit (règle métier 3 de CLAUDE.md :
 *   archivage d'un bien et création du nouvel actif dans la même
 *   transaction, tout en respectant la règle 5 qui impose de passer par la
 *   chaîne d'audit). Le verrou est pris avant l'ouverture de l'unique
 *   transaction qui englobe travail métier et écriture d'audit, et n'est
 *   relâché qu'après son COMMIT.
 *
 * Conséquence permanente pour les tests : append() ET transaction() refusent
 * toutes deux de s'exécuter depuis une transaction déjà ouverte (voir
 * ci-dessus) — or le trait RefreshDatabase de Laravel enveloppe chaque test
 * dans sa propre transaction, annulée (ROLLBACK) en fin de test. Un test qui
 * utiliserait RefreshDatabase tout en appelant append() ou transaction()
 * échouerait donc systématiquement sur ce garde-fou, pour un faux positif :
 * ce n'est pas une transaction métier englobante, seulement l'isolation du
 * test. RefreshDatabase est donc inutilisable pour tout test qui exerce une
 * action métier auditée ; la table doit être réinitialisée explicitement
 * (migration si elle est absente, puis TRUNCATE TABLE audit_log avant et
 * après chaque test), comme le pratiquent déjà tests/Feature/AuditChainTest.php,
 * tests/Feature/AuditChainConcurrencyTest.php,
 * tests/Feature/AuditChainTransactionConcurrencyTest.php et
 * tests/BusinessRules/ChaineAuditInalterableTest.php.
 *
 * L'inaltérabilité ne repose pas uniquement sur cette classe : des
 * déclencheurs MariaDB (§4.6 de la spec) interdisent tout UPDATE/DELETE sur
 * audit_log au niveau du moteur, quel que soit le chemin de code emprunté.
 */
final class AuditChain
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Délai d'attente du verrou nommé, en secondes. Volontairement court :
     * si l'écriture d'audit ne peut pas être sérialisée rapidement, mieux
     * vaut échouer explicitement (l'appelant sait alors que l'action n'a pas
     * été journalisée) que de bloquer la requête PHP jusqu'à la limite de
     * temps d'exécution d'un hébergement mutualisé.
     */
    private const LOCK_TIMEOUT_SECONDS = 5;

    /**
     * Nombre d'essais de la transaction elle-même — modeste, car la
     * sérialisation vient du verrou nommé, pas de la reprise : une fois le
     * verrou obtenu, la transaction n'est plus en concurrence avec aucune
     * autre écriture d'audit. Cette petite marge n'absorbe qu'un aléa
     * transitoire (ex. une micro-coupure de connexion), jamais une
     * contention réelle.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     * @throws RuntimeException si appelée depuis une transaction déjà
     *                          ouverte, ou si le verrou d'écriture n'est pas
     *                          obtenu dans le délai imparti
     */
    public function append(
        ActorType $actorType,
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        array $payload,
    ): AuditLog {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'AuditChain::append() ne peut pas être appelé depuis une transaction déjà ouverte : le verrou serait '.
                'relâché avant le COMMIT de l\'appelant, ce qui viderait la sérialisation de son effet — une revue '.
                "l'a démontré par des entrées d'audit perdues sous écriture concurrente réelle. Utilisez ".
                'AuditChain::transaction() pour englober travail métier et écriture d\'audit dans une seule '.
                'transaction sérialisée.'
            );
        }

        $columns = $this->buildColumns($actorType, $actorId, $action, $entityType, $entityId, $payload);
        $recordHash = $this->computeRecordHash($columns);

        $this->acquireLock();

        try {
            $id = DB::transaction(
                fn (): int => $this->insertEntry($columns, $recordHash),
                self::TRANSACTION_ATTEMPTS,
            );
        } finally {
            $this->releaseLock();
        }

        return AuditLog::findOrFail($id);
    }

    /**
     * Englobe un travail métier et l'écriture de son entrée d'audit dans une
     * seule et même transaction, sérialisée par le même verrou nommé
     * qu'append(). Le verrou est pris avant l'ouverture de cette transaction
     * et englobe tout le travail métier : c'est le point d'entrée à utiliser
     * quand l'action métier elle-même doit rester transactionnelle avec son
     * entrée d'audit (voir la docstring de classe).
     *
     * $work reçoit et ne prend aucun argument, exécute le travail métier,
     * puis retourne le résultat à renvoyer à l'appelant ainsi que les
     * paramètres de l'entrée d'audit à écrire dans la même transaction —
     * l'identifiant de l'entité concernée n'est souvent connu qu'une fois ce
     * travail exécuté (ex. l'identifiant d'un nouvel actif créé).
     *
     * ATTENTION — le travail métier confié à $work doit rester court. Le
     * verrou nommé englobe tout le temps d'exécution de $work : toute autre
     * action métier auditée, même sans aucun rapport avec celle-ci, attend
     * derrière le même verrou pendant ce temps. Une revue a mesuré, avec une
     * barrière de départ commune : 8 processus dont le travail métier dure
     * 300 ms → 8 sur 8 réussissent ; 16 processus à 300 ms → 5 sur 16
     * seulement, les 11 autres rejetés faute d'obtenir le verrou dans le
     * délai imparti (LOCK_TIMEOUT_SECONDS, 5 s). La règle est linéaire :
     * concurrence servie ≈ délai du verrou ÷ durée du travail métier, soit
     * environ 16 actions simultanées servies à 300 ms de travail métier
     * chacune, et seulement 5 à une seconde. C'est sûr — la transaction est
     * annulée, aucune entrée n'est perdue, aucune fourche — mais c'est un
     * plafond de disponibilité, pas une simple lenteur : au-delà, des actions
     * métier sans aucun rapport entre elles se rejettent mutuellement. Tout
     * ce qui peut être fait avant ou après $work — préparer des données,
     * valider une saisie, appeler un service externe — doit l'être en dehors
     * du callback, jamais à l'intérieur.
     *
     * ATTENTION — aucun effet de bord non transactionnel dans $work. Sur
     * interblocage avec un écrivain d'une autre table, `DB::transaction()`
     * rejoue le callback en entier (jusqu'à TRANSACTION_ATTEMPTS fois) : le
     * travail métier s'exécute donc, lui aussi, plusieurs fois. Sans
     * conséquence pour une simple insertion (rejouable sans risque), mais
     * dangereux dès que $work déclenche un effet qui n'est pas annulé par un
     * ROLLBACK — un appel à une passerelle de paiement, un envoi de SMS, un
     * dépôt de fichier sur un stockage objet : rejoué, un paiement serait
     * débité deux fois, un SMS envoyé deux fois, un fichier déposé deux fois.
     *
     * @template TReturn
     *
     * @param  callable(): array{result: TReturn, actorType: ActorType, actorId: int|null, action: string, entityType: string, entityId: int, payload: array<string, mixed>}  $work
     * @return TReturn
     *
     * @throws JsonException
     * @throws RuntimeException si appelée depuis une transaction déjà
     *                          ouverte, ou si le verrou d'écriture n'est pas
     *                          obtenu dans le délai imparti
     */
    public function transaction(callable $work): mixed
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'AuditChain::transaction() doit être la transaction la plus externe : ne l\'appelez jamais depuis '.
                'une transaction déjà ouverte — ouvrez-la en premier et laissez-la englober le travail métier.'
            );
        }

        $this->acquireLock();

        try {
            return DB::transaction(function () use ($work): mixed {
                $outcome = $work();

                $columns = $this->buildColumns(
                    $outcome['actorType'],
                    $outcome['actorId'],
                    $outcome['action'],
                    $outcome['entityType'],
                    $outcome['entityId'],
                    $outcome['payload'],
                );

                $this->insertEntry($columns, $this->computeRecordHash($columns));

                return $outcome['result'];
            }, self::TRANSACTION_ATTEMPTS);
        } finally {
            $this->releaseLock();
        }
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
     * Nom du verrou nommé MariaDB (GET_LOCK) qui sérialise les écritures.
     * Préfixé par l'application et suffixé par le nom de la base de la
     * connexion par défaut : l'hébergement cible est un mutualisé où le
     * verrou est global au serveur de base, pas à la seule base courante —
     * un nom qui ne distinguerait pas les bases (ex. `preuve` en production
     * et `preuve_staging` en préproduction sur le même serveur MariaDB) les
     * ferait s'affamer mutuellement, sans aucun rapport entre elles.
     */
    private function lockName(): string
    {
        return 'preuve:audit_log:append_chain:'.DB::connection()->getDatabaseName();
    }

    /**
     * @throws RuntimeException si le verrou n'est pas obtenu dans le délai
     *                          imparti
     */
    private function acquireLock(): void
    {
        $lockResult = DB::scalar('SELECT GET_LOCK(?, ?)', [$this->lockName(), self::LOCK_TIMEOUT_SECONDS]);
        $acquired = is_scalar($lockResult) && (int) $lockResult === 1;

        if (! $acquired) {
            throw new RuntimeException(
                "Chaîne d'audit : verrou d'écriture non obtenu dans le délai imparti — rien n'a été journalisé."
            );
        }
    }

    /**
     * Libéré dans tous les chemins de sortie, y compris sur exception : un
     * verrou nommé qui ne serait jamais relâché bloquerait toute écriture
     * d'audit ultérieure jusqu'à la fin de la session ou du process MariaDB
     * qui le détient.
     */
    private function releaseLock(): void
    {
        DB::statement('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{actor_type: string, actor_id: int|null, action: string, entity_type: string, entity_id: int, payload: string, created_at: string}
     *
     * @throws JsonException
     */
    private function buildColumns(
        ActorType $actorType,
        ?int $actorId,
        string $action,
        string $entityType,
        int $entityId,
        array $payload,
    ): array {
        return [
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $this->canonicalJson($payload),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Lit le dernier chain_hash sous verrou de ligne et insère la nouvelle
     * entrée. Suppose être déjà appelée à l'intérieur d'une transaction
     * ouverte par l'appelant (append() ou transaction()) : cette méthode ne
     * gère elle-même ni le verrou nommé ni la transaction.
     *
     * @param  array{actor_type: string, actor_id: int|null, action: string, entity_type: string, entity_id: int, payload: string, created_at: string}  $columns
     */
    private function insertEntry(array $columns, string $recordHash): int
    {
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
