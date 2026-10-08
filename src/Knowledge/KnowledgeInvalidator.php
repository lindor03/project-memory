<?php

namespace ProjectMemory\Knowledge;

use ProjectMemory\Models\AiKnowledge;

class KnowledgeInvalidator
{
    /**
     * @param  array<string, string>  $changedHashes
     * @param  list<string>  $deletedKeys
     */
    public function invalidate(array $changedHashes, array $deletedKeys): int
    {
        $count = 0;
        $deleted = array_fill_keys($deletedKeys, true);
        AiKnowledge::query()
            ->whereIn('state', [AiKnowledge::STATE_ACTIVE, AiKnowledge::STATE_DRAFT])
            ->chunkById(200, function ($rows) use ($changedHashes, $deleted, &$count) {
                foreach ($rows as $row) {
                    $reason = $row->expires_at !== null && $row->expires_at->lte(now())
                        ? 'Knowledge expired; explicit re-verification is required.'
                        : $this->reason($row, $changedHashes, $deleted);
                    if ($reason === null) {
                        continue;
                    }

                    $row->update([
                        'state' => AiKnowledge::STATE_STALE,
                        'invalidated_at' => now(),
                        'invalidation_reason' => $reason,
                        'metadata' => array_merge($row->metadata ?? [], ['confidence' => 0.0]),
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  array<string, string>  $changedHashes
     * @param  array<string, bool>  $deletedKeys
     */
    private function reason(AiKnowledge $row, array $changedHashes, array $deletedKeys): ?string
    {
        foreach ($row->source_hashes ?? [] as $key => $hash) {
            if (isset($deletedKeys[$key])) {
                return 'Referenced symbol was removed: '.$key;
            }

            if (isset($changedHashes[$key]) && $changedHashes[$key] !== $hash) {
                return 'Referenced source changed: '.$key;
            }
        }

        foreach ($row->source_refs ?? [] as $ref) {
            $key = $ref['node_key'] ?? null;
            if (is_string($key) && isset($deletedKeys[$key])) {
                return 'Referenced symbol was removed: '.$key;
            }
            if (is_string($key) && isset($changedHashes[$key]) && ! isset(($row->source_hashes ?? [])[$key])) {
                return 'Referenced source changed without captured verification: '.$key;
            }
        }

        return null;
    }
}
