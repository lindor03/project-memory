<?php

namespace ProjectMemory\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModule extends MemoryModel
{
    protected $table = 'ai_modules';

    protected $fillable = [
        'key',
        'name',
        'root_path',
        'summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AiCodeNode::class, 'module_id');
    }
}
