<?php

namespace ProjectMemory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCodeNode extends MemoryModel
{
    protected $table = 'ai_code_nodes';

    protected $fillable = [
        'module_id',
        'node_key',
        'file_path',
        'path_hash',
        'symbol_name',
        'node_type',
        'content_hash',
        'start_line',
        'end_line',
        'summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(AiModule::class, 'module_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(AiCodeEdge::class, 'source_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(AiCodeEdge::class, 'target_node_id');
    }
}
