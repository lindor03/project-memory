<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRetriever;
use ProjectMemory\Context\TokenEstimator;
use ProjectMemory\Maintenance\IndexHealth;
use ProjectMemory\Models\AiAgentRun;
use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiIndexRun;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Models\AiModule;
use ProjectMemory\Schema\SchemaInstaller;
use ProjectMemory\Support\MemoryConnection;

class StatusCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:status {--json : Machine-readable output}';

    protected $description = 'Show project-memory index counts, last run, and a token estimate';

    public function handle(SchemaInstaller $schema, MemoryConnection $memory, ContextRetriever $retriever, TokenEstimator $estimator, IndexHealth $health): int
    {
        if (! $schema->ready()) {
            return $this->emit([
                'text' => "Project memory is not initialized. Run php artisan ai:scan.\n",
                'initialized' => false,
            ], self::SUCCESS);
        }

        $last = AiIndexRun::query()->latest('id')->first();
        $bytes = 0;
        AiCodeNode::query()->where('node_type', 'file')->select(['id', 'metadata'])->chunkById(200, function ($nodes) use (&$bytes) {
            foreach ($nodes as $node) {
                $bytes += (int) ($node->metadata['bytes'] ?? 0);
            }
        });
        $overview = $retriever->retrieve('project_overview', ['budget' => 800]);
        $naive = (int) ceil($bytes / $estimator->charsPerToken());
        $states = AiKnowledge::query()->selectRaw('state, COUNT(*) AS aggregate')->groupBy('state')->pluck('aggregate', 'state');
        $telemetry = AiAgentRun::query()->selectRaw('COUNT(*) AS retrievals, AVG(latency_ms) AS average_latency_ms, SUM(estimated_input_tokens) AS estimated_context_tokens, SUM(retrieved_context_size) AS retrieved_characters')->first();
        $payload = $health->inspect() + [
            'initialized' => AiCodeNode::query()->exists(),
            'connection' => $memory->name(),
            'database' => $memory->databaseName(),
            'modules' => AiModule::query()->count(),
            'nodes' => AiCodeNode::query()->count(),
            'edges' => AiCodeEdge::query()->count(),
            'knowledge' => AiKnowledge::query()->count(),
            'active_knowledge' => (int) $states->get(AiKnowledge::STATE_ACTIVE, 0),
            'stale_knowledge' => (int) $states->get(AiKnowledge::STATE_STALE, 0),
            'knowledge_states' => $states->all(),
            'retrieval_metrics' => [
                'retrievals' => (int) $telemetry->retrievals,
                'average_latency_ms' => round((float) $telemetry->average_latency_ms, 2),
                'estimated_context_tokens' => (int) $telemetry->estimated_context_tokens,
                'retrieved_characters' => (int) $telemetry->retrieved_characters,
                'basis' => 'Recorded retrievals; estimates are not measured model billing or retrieval accuracy.',
            ],
            'last_run' => $last?->only(['id', 'scan_scope', 'status', 'files_scanned', 'files_updated', 'files_skipped', 'deleted_nodes', 'duration_ms', 'errors', 'finished_at']),
            'indexed_file_bytes' => $bytes,
            'naive_estimated_tokens' => $naive,
            'overview_estimated_tokens' => $overview->estimatedTokens,
            'token_basis' => 'estimated',
        ];
        $payload['text'] = sprintf(
            "Connection %s (%s)\nNodes %d, edges %d, modules %d\nLast run: %s\nNaive source estimate %d tokens; overview estimate %d tokens.\n",
            $payload['connection'],
            $payload['database'],
            $payload['nodes'],
            $payload['edges'],
            $payload['modules'],
            $last?->status ?? 'never',
            $naive,
            $overview->estimatedTokens
        );

        return $this->emit($payload);
    }
}
