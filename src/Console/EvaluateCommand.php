<?php

namespace ProjectMemory\Console;

use ProjectMemory\Evaluation\ApplicationScenarios;
use ProjectMemory\Evaluation\RetrievalEvaluator;

class EvaluateCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:evaluate {--k=8} {--budget=2500} {--json : Machine-readable output}';

    protected $description = 'Measure retrieval against reviewed application scenarios';

    public function handle(RetrievalEvaluator $evaluator): int
    {
        $report = $evaluator->run(ApplicationScenarios::all(), max(1, (int) $this->option('k')), max(1, (int) $this->option('budget')));
        $report['workflows'] = [
            'standard_cursor' => 'Estimated tokens of the reviewed source files, read in full. This is not a Cursor billing measurement.',
            'project_memory' => 'estimated_context_tokens on each scenario.',
            'project_memory_and_boost' => 'Add a narrow search-docs response only when context_route includes laravel_boost. This command does not call Boost.',
        ];
        $full = array_sum(array_column($report['results'], 'full_source_estimated_tokens'));
        $memory = array_sum(array_column($report['results'], 'estimated_context_tokens'));
        $report['comparison'] = [
            'full_source_estimated_tokens' => $full,
            'project_memory_estimated_tokens' => $memory,
            'provider_reported_input_tokens' => null,
            'provider_reported_output_tokens' => null,
            'cached_tokens' => null,
        ];

        return $this->emit($report + [
            'text' => 'Recall@'.$report['k'].' '.$report['mean_recall_at_k'].', precision '.$report['mean_precision_at_k'].', MRR '.$report['mean_reciprocal_rank'].'.'."\n",
        ]);
    }
}
