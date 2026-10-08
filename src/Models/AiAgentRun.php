<?php

namespace ProjectMemory\Models;

class AiAgentRun extends MemoryModel
{
    protected $table = 'ai_agent_runs';

    protected $fillable = [
        'operation',
        'agent',
        'model',
        'reported_input_tokens',
        'reported_output_tokens',
        'reported_cached_tokens',
        'retrieved_context_size',
        'estimated_input_tokens',
        'estimated_output_tokens',
        'token_basis',
        'latency_ms',
        'cost',
        'cost_currency',
        'cost_is_estimated',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'cost' => 'decimal:6',
            'cost_is_estimated' => 'boolean',
        ];
    }
}
