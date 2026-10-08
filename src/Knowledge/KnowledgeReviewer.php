<?php

namespace ProjectMemory\Knowledge;

use ProjectMemory\Models\AiKnowledge;

/** Reports knowledge that lacks verification evidence. It never approves or restores trust. */
class KnowledgeReviewer
{
    public function review(): array
    {
        $unverified = [];
        $stale = 0;
        AiKnowledge::query()->orderBy('id')->chunkById(200, function ($rows) use (&$unverified, &$stale) {
            foreach ($rows as $row) {
                if ($row->state === AiKnowledge::STATE_STALE) {
                    $stale++;
                }
                if (! in_array($row->state, [AiKnowledge::STATE_ACTIVE, AiKnowledge::STATE_DRAFT], true)) {
                    continue;
                }
                $hashes = $row->source_hashes ?? [];
                if ($hashes !== []) {
                    continue;
                }
                $unverified[] = [
                    'knowledge_key' => $row->knowledge_key,
                    'state' => $row->state,
                    'title' => $row->title,
                    'reason' => 'No source hashes were captured for this assertion.',
                ];
            }
        });

        return [
            'unverified_active_or_draft' => count($unverified),
            'examples' => array_slice($unverified, 0, 5),
            'stale' => $stale,
            'promotion' => 'disabled',
            'action' => 'Revise with ai:learn --revise=<knowledge_key> and fresh evidence. Approval is explicit and is never inherited from an older version.',
        ];
    }

    public function markUnverifiedStale(): int
    {
        $count = 0;
        AiKnowledge::query()
            ->whereIn('state', [AiKnowledge::STATE_ACTIVE, AiKnowledge::STATE_DRAFT])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    if (($row->source_hashes ?? []) !== []) {
                        continue;
                    }
                    $row->update([
                        'state' => AiKnowledge::STATE_STALE,
                        'invalidated_at' => now(),
                        'invalidation_reason' => 'Legacy knowledge has no verification hashes. Explicit revision is required.',
                        'metadata' => array_merge($row->metadata ?? [], ['confidence' => 0.0, 'revalidation' => 'marked_stale']),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
