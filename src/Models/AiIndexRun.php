<?php

namespace ProjectMemory\Models;

class AiIndexRun extends MemoryModel
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_index_runs';

    protected $fillable = [
        'scan_scope',
        'git_revision',
        'files_scanned',
        'files_updated',
        'files_skipped',
        'deleted_nodes',
        'duration_ms',
        'errors',
        'status',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
