<?php

namespace ProjectMemory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCodeEdge extends MemoryModel
{
    protected $table = 'ai_code_edges';

    protected $fillable = [
        'source_node_id',
        'target_node_id',
        'relationship',
        'confidence',
        'metadata',
        'declaration_path_hash',
        'reference_hash',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'confidence' => 'decimal:2',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AiCodeNode::class, 'source_node_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(AiCodeNode::class, 'target_node_id');
    }
}
