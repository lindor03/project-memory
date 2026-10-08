<?php

namespace ProjectMemory\Knowledge;

use Illuminate\Support\Str;
use ProjectMemory\Context\SourceFreshness;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Support\NodeKeys;

class KnowledgeStore
{
    public function __construct(private readonly SourceFreshness $freshness) {}

    /**
     * @param  list<string>  $symbols
     * @param  list<string>  $files
     */
    public function record(
        string $title,
        string $body,
        string $kind,
        string $state,
        array $symbols = [],
        array $files = [],
        ?string $verifiedBy = null,
    ): AiKnowledge {
        if (trim($title) === '' || mb_strlen($title) > 255 || trim($body) === '') {
            throw new \InvalidArgumentException('Knowledge requires a non-empty title of at most 255 characters and a non-empty body.');
        }
        if (! in_array($kind, ['architectural_rule', 'module_summary', 'convention', 'decision', 'pattern', 'lesson'], true)) {
            throw new \InvalidArgumentException('Unsupported knowledge kind ['.$kind.'].');
        }
        if (! in_array($state, AiKnowledge::STATES, true) || $state === AiKnowledge::STATE_STALE) {
            throw new \InvalidArgumentException('Knowledge state must be draft, active, rejected, or archived. Active knowledge requires explicit approval.');
        }

        [$refs, $hashes, $unverified] = $this->evidence($symbols, $files);
        if ($state === AiKnowledge::STATE_ACTIVE && $unverified !== []) {
            throw new \InvalidArgumentException('Active knowledge requires fresh, unambiguous source references. Run ai:sync or save a draft: '.implode(', ', $unverified));
        }

        $approved = $state === AiKnowledge::STATE_ACTIVE;
        $key = substr(Str::slug($kind.'-'.$title), 0, 140).'-'.Str::uuid();

        return AiKnowledge::query()->create([
            'knowledge_key' => $key,
            'kind' => $kind,
            'title' => trim($title),
            'body' => trim($body),
            'state' => $state,
            'source_refs' => $refs,
            'source_hashes' => $hashes,
            'verified_at' => $approved ? now() : null,
            'verified_by' => $approved ? ($verifiedBy ?: 'cli') : null,
            'metadata' => [
                'approved' => $approved,
                'version' => 1,
                'lineage_key' => $key,
                'content_fingerprint' => hash('sha256', $kind."\0".trim($title)."\0".trim($body)),
                'confidence' => $approved ? ($refs === [] ? 0.5 : 0.9) : 0.0,
                'confidence_basis' => 'Approval and source coverage; prose remains an advisory assertion.',
                'unverified_refs' => $unverified,
                'provenance' => [
                    'type' => $approved ? ($refs === [] ? 'approved_assertion' : 'source_backed_assertion') : 'suggested_assertion',
                    'actor' => $verifiedBy ?: 'cli',
                    'recorded_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /** Preserve prior content and evidence. New versions require their own approval. */
    public function revise(
        string $previousKey, string $title, string $body, string $kind, string $state,
        array $symbols = [], array $files = [], ?string $verifiedBy = null,
    ): AiKnowledge {
        return AiKnowledge::query()->getConnection()->transaction(function () use ($previousKey, $title, $body, $kind, $state, $symbols, $files, $verifiedBy) {
            $previous = AiKnowledge::query()->where('knowledge_key', $previousKey)->lockForUpdate()->first();
            if ($previous === null) {
                throw new \InvalidArgumentException('No knowledge matches ['.$previousKey.'].');
            }
            if (isset($previous->metadata['superseded_by'])) {
                throw new \InvalidArgumentException('Knowledge already has a newer version ['.$previous->metadata['superseded_by'].']. Revise that version instead.');
            }
            $next = $this->record($title, $body, $kind, $state, $symbols, $files, $verifiedBy);
            $next->update(['metadata' => array_merge($next->metadata, [
                'version' => $previous->version + 1,
                'lineage_key' => $previous->metadata['lineage_key'] ?? $previous->knowledge_key,
                'previous_key' => $previous->knowledge_key,
            ])]);
            $previous->update([
                'state' => AiKnowledge::STATE_ARCHIVED,
                'metadata' => array_merge($previous->metadata ?? [], ['superseded_by' => $next->knowledge_key]),
            ]);

            return $next;
        });
    }

    /**
     * Archive the current body and attach fresh file evidence to the same key.
     * Approval is refused when any cited file is missing, stale, or unindexed.
     */
    public function reaffirm(
        string $knowledgeKey,
        string $title,
        string $body,
        array $files,
        bool $approve,
        string $verifiedBy,
        string $review,
    ): AiKnowledge {
        if (trim($title) === '' || trim($body) === '' || trim($review) === '') {
            throw new \InvalidArgumentException('A reaffirmation requires a title, body, and review note.');
        }

        return AiKnowledge::query()->getConnection()->transaction(function () use ($knowledgeKey, $title, $body, $files, $approve, $verifiedBy, $review) {
            $current = AiKnowledge::query()->where('knowledge_key', $knowledgeKey)->lockForUpdate()->first();
            if ($current === null) {
                throw new \InvalidArgumentException('No knowledge matches ['.$knowledgeKey.'].');
            }
            if (isset($current->metadata['superseded_by'])) {
                throw new \InvalidArgumentException('Knowledge already has a newer version ['.$current->metadata['superseded_by'].']. Revise that version instead.');
            }
            [$refs, $hashes, $unverified] = $this->evidence([], $files);
            if ($approve && ($unverified !== [] || $hashes === [])) {
                throw new \InvalidArgumentException('Active knowledge requires fresh, unambiguous source references. Run ai:sync or save a draft: '.implode(', ', $unverified));
            }
            $archiveKey = substr($knowledgeKey.'-v'.$current->version, 0, 100).'-'.Str::uuid();
            $lineage = $current->metadata['lineage_key'] ?? $current->knowledge_key;
            AiKnowledge::query()->create([
                'knowledge_key' => $archiveKey,
                'kind' => $current->kind,
                'title' => $current->title,
                'body' => $current->body,
                'state' => AiKnowledge::STATE_ARCHIVED,
                'source_refs' => $current->source_refs,
                'source_hashes' => $current->source_hashes ?? [],
                'verified_at' => $current->verified_at,
                'verified_by' => $current->verified_by,
                'metadata' => array_merge($current->metadata ?? [], [
                    'version' => $current->version,
                    'lineage_key' => $lineage,
                    'superseded_by' => $current->knowledge_key,
                    'archived_reason' => 'Revision snapshot before source revalidation.',
                ]),
            ]);
            $current->update([
                'title' => trim($title),
                'body' => trim($body),
                'state' => $approve ? AiKnowledge::STATE_ACTIVE : AiKnowledge::STATE_DRAFT,
                'source_refs' => $refs,
                'source_hashes' => $hashes,
                'verified_at' => $approve ? now() : null,
                'verified_by' => $approve ? $verifiedBy : null,
                'invalidated_at' => null,
                'invalidation_reason' => null,
                'metadata' => [
                    'catalog' => (bool) ($current->metadata['catalog'] ?? false),
                    'approved' => $approve,
                    'version' => $current->version + 1,
                    'lineage_key' => $lineage,
                    'previous_key' => $archiveKey,
                    'content_fingerprint' => hash('sha256', $current->kind."\0".trim($title)."\0".trim($body)),
                    'confidence' => $approve ? 0.9 : 0.0,
                    'confidence_basis' => 'Explicit revalidation against indexed source hashes. Prose remains advisory.',
                    'unverified_refs' => $unverified,
                    'review' => $review,
                    'provenance' => [
                        'type' => $approve ? 'source_backed_assertion' : 'suggested_assertion',
                        'actor' => $verifiedBy,
                        'recorded_at' => now()->toIso8601String(),
                        'prior_verified_by' => $current->verified_by,
                    ],
                ],
            ]);

            return $current->refresh();
        });
    }

    /** @return array{array, array<string, string>, list<string>} */
    private function evidence(array $symbols, array $files): array
    {
        $this->freshness->reset();
        $symbols = array_values(array_unique(array_map('trim', $symbols)));
        $files = array_values(array_unique(array_map(fn (string $file) => $this->relativePath($file), $files)));
        $nodes = AiCodeNode::query()->where(function ($query) use ($symbols, $files) {
            $query->whereIn('node_key', $symbols)->orWhereIn('symbol_name', $symbols)
                ->orWhere(function ($query) use ($files) {
                    $query->where('node_type', 'file')->whereIn('file_path', $files);
                });
        })->get();
        $byKey = $nodes->keyBy('node_key');
        $bySymbol = $nodes->groupBy('symbol_name');
        $fileNodes = $nodes->where('node_type', 'file')->keyBy('file_path');
        $resolved = [];
        foreach ($symbols as $symbol) {
            $node = $byKey->get($symbol);
            if ($node === null) {
                $matches = $bySymbol->get($symbol, collect());
                if ($matches->count() > 1) {
                    throw new \InvalidArgumentException('Ambiguous symbol ['.$symbol.']; provide an exact node key.');
                }
                $node = $matches->first();
            }
            $resolved[$node?->node_key ?? $symbol] = [$node, $symbol, $node?->file_path];
        }
        foreach ($files as $file) {
            $node = $fileNodes->get($file);
            $resolved[$node?->node_key ?? NodeKeys::file($file)] = [$node, NodeKeys::file($file), $file];
        }
        $this->freshness->prime($nodes);
        $refs = $hashes = $unverified = [];
        foreach ($resolved as $key => [$node, $requested, $path]) {
            $ref = ['node_key' => $key, 'file' => $path, 'type' => $node?->node_type];
            if (is_string($path)) {
                $path = $this->relativePath($path);
                if ($node?->node_type === 'file') {
                    $ref['source_file_hash'] = $this->freshness->liveHash($path);
                }
            }
            $fresh = $node !== null && $node->content_hash !== null && $this->freshness->node($node);
            if ($fresh) {
                $hashes[$key] = $node->content_hash;
            } else {
                $unverified[] = $requested;
            }
            $refs[] = $ref;
        }

        return [$refs, $hashes, $unverified];
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path)
            || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new \InvalidArgumentException('Source paths must remain relative to the repository: ['.$path.'].');
        }

        return preg_replace('~^(?:\./)+~', '', $path);
    }
}
