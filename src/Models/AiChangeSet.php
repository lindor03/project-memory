<?php

namespace ProjectMemory\Models;

class AiChangeSet extends MemoryModel
{
    protected $table = 'ai_change_sets';

    protected $fillable = [
        'intent',
        'git_commit',
        'changed_files',
        'changed_symbols',
        'expected_impact',
        'actual_impact',
        'validation_outcome',
        'lessons',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'changed_files' => 'array',
            'changed_symbols' => 'array',
            'metadata' => 'array',
        ];
    }
}
