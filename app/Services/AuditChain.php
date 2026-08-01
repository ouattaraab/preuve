<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActorType;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Journal inaltérable à hash chaîné.
 *
 * chain_hash = SHA-256(prev_hash || payload_hash)
 *
 * Le dernier chain_hash est lu sous verrou pour garantir la continuité même
 * sous écriture concurrente. Le verrou est court par construction : la
 * transaction ne contient qu'une lecture et une insertion, ce qui évite de
 * heurter la limite de temps d'exécution d'un hébergement mutualisé.
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
        $payloadHash = $this->hashPayload($payload);

        return DB::transaction(function () use (
            $actorType, $actorId, $action, $entityType, $entityId, $payload, $payloadHash
        ): AuditLog {
            $previousChainHash = DB::table('audit_log')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('chain_hash');

            $prevHash = is_string($previousChainHash) ? $previousChainHash : self::GENESIS_HASH;
            $chainHash = hash('sha256', $prevHash.$payloadHash);

            $id = DB::table('audit_log')->insertGetId([
                'actor_type' => $actorType->value,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload' => $this->canonicalJson($payload),
                'payload_hash' => $payloadHash,
                'prev_hash' => $prevHash,
                'chain_hash' => $chainHash,
                'created_at' => now(),
            ]);

            return AuditLog::findOrFail($id);
        });
    }

    /**
     * Rejoue la chaîne complète et signale la première entrée incohérente.
     *
     * @return array{valid: bool, broken_at: int|null}
     */
    public function verify(): array
    {
        $prevHash = self::GENESIS_HASH;

        foreach (AuditLog::orderBy('id')->cursor() as $entry) {
            $rawPayload = $entry->getRawOriginal('payload');
            $decoded = is_string($rawPayload) ? json_decode($rawPayload, true) : null;
            $expectedPayloadHash = $this->hashPayload(is_array($decoded) ? $this->stringKeyed($decoded) : []);
            $expectedChainHash = hash('sha256', $prevHash.$expectedPayloadHash);

            $intact = $entry->prev_hash === $prevHash
                && $entry->payload_hash === $expectedPayloadHash
                && $entry->chain_hash === $expectedChainHash;

            if (! $intact) {
                return ['valid' => false, 'broken_at' => (int) $entry->id];
            }

            $prevHash = $entry->chain_hash;
        }

        return ['valid' => true, 'broken_at' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * Sérialisation canonique : sans tri récursif des clés, deux payloads
     * identiques mais ordonnés différemment produiraient des hash différents
     * et la vérification de chaîne échouerait à tort.
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
     * Les clés d'un objet JSON sont toujours des chaînes : cette conversion
     * rend explicite pour l'analyse statique une garantie déjà vraie à
     * l'exécution pour tout payload décodé depuis la colonne `payload`.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
