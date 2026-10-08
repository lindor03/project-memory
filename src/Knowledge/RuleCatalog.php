<?php

namespace ProjectMemory\Knowledge;

use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Models\AiModule;

class RuleCatalog
{
    public function __construct(private readonly KnowledgeStore $knowledge) {}

    public function sync(): int
    {
        $count = 0;

        $catalog = (array) config('project-memory.knowledge.catalog', []);
        $existing = AiKnowledge::query()->whereIn('knowledge_key', array_keys($catalog))->pluck('knowledge_key')->flip();
        foreach ($catalog as $key => $rule) {
            if ($existing->has($key)) {
                continue;
            }
            $row = $this->knowledge->record(
                (string) $rule['title'],
                (string) $rule['body'],
                'architectural_rule',
                AiKnowledge::STATE_DRAFT,
                [],
                array_column($rule['refs'] ?? [], 'file'),
                'rule-catalog',
            );
            $row->update([
                'knowledge_key' => $key,
                'metadata' => array_merge($row->metadata, ['catalog' => true, 'lineage_key' => $key]),
            ]);
            $count++;
        }

        $summaries = (array) config('project-memory.knowledge.module_summaries', []);
        $emptyModules = AiModule::query()->whereIn('key', array_keys($summaries))->where(function ($query) {
            $query->whereNull('summary')->orWhere('summary', '');
        })->get(['id', 'key']);
        foreach ($emptyModules as $module) {
            $module->update(['summary' => $summaries[$module->key]]);
        }

        return $count;
    }
}
