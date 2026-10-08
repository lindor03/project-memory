<?php

namespace ProjectMemory\Models;

class AiKnowledge extends MemoryModel
{
    public const STATE_DRAFT = 'draft';

    public const STATE_ACTIVE = 'active';

    public const STATE_STALE = 'stale';

    public const STATE_REJECTED = 'rejected';

    public const STATE_ARCHIVED = 'archived';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_ACTIVE,
        self::STATE_STALE,
        self::STATE_REJECTED,
        self::STATE_ARCHIVED,
    ];

    protected $table = 'ai_knowledge';

    protected $fillable = [
        'knowledge_key',
        'kind',
        'title',
        'body',
        'state',
        'source_refs',
        'source_hashes',
        'verified_at',
        'verified_by',
        'expires_at',
        'invalidated_at',
        'invalidation_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_refs' => 'array',
            'source_hashes' => 'array',
            'metadata' => 'array',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function getVersionAttribute(): int
    {
        return max(1, (int) ($this->metadata['version'] ?? 1));
    }

    public function getConfidenceAttribute(): float
    {
        $confidence = $this->metadata['confidence'] ?? ($this->state === self::STATE_ACTIVE ? 0.5 : 0.0);

        return max(0.0, min(1.0, (float) $confidence));
    }
}
