<?php

namespace ProjectMemory\Evaluation;

use ProjectMemory\Context\ContextRetriever;
use ProjectMemory\Context\TokenEstimator;

/**
 * Measures retrieval against a reviewed expected set.
 * Token figures are local estimates, not provider billing.
 */
class RetrievalEvaluator
{
    public function __construct(
        private readonly ContextRetriever $retriever,
        private readonly TokenEstimator $tokens,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $scenarios
     * @return array<string, mixed>
     */
    public function run(array $scenarios, int $k = 8, int $budget = 2500): array
    {
        $results = [];
        $recall = $packetRecallTotal = $precision = $mrr = $latency = $context = 0.0;
        $counted = 0;
        foreach ($scenarios as $scenario) {
            $started = hrtime(true);
            $parameters = $scenario['parameters'] ?? [];
            $parameters['budget'] = $parameters['budget'] ?? $budget;
            $packet = $this->retriever->retrieve((string) $scenario['operation'], $parameters);
            $elapsed = (hrtime(true) - $started) / 1e6;
            $ranked = array_slice($packet->sources, 0, $k);
            $expected = array_values(array_unique(array_map(fn (string $value) => strtolower(str_replace('\\', '/', $value)), $scenario['expected'] ?? [])));
            $hits = array_values(array_filter($expected, fn (string $item) => $this->hit($item, $ranked)));
            $relevantSources = 0;
            $rank = null;
            foreach ($ranked as $index => $source) {
                $matched = false;
                foreach ($expected as $item) {
                    if ($this->sourceMatches($item, $source)) {
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    $relevantSources++;
                    $rank ??= $index + 1;
                }
            }
            $packetHits = array_values(array_filter($expected, fn (string $item) => $this->hit($item, $packet->sources)));
            $scenarioRecall = $expected === [] ? 0.0 : count($hits) / count($expected);
            $packetRecall = $expected === [] ? 0.0 : count($packetHits) / count($expected);
            $scenarioPrecision = $ranked === [] ? 0.0 : $relevantSources / count($ranked);
            $fullSource = $this->sourceTokens($scenario['files'] ?? []);
            $results[] = [
                'id' => $scenario['id'],
                'recall_at_k' => round($scenarioRecall, 4),
                'recall_in_packet' => round($packetRecall, 4),
                'precision_at_k' => round($scenarioPrecision, 4),
                'reciprocal_rank' => $rank === null ? 0.0 : round(1 / $rank, 4),
                'latency_ms' => round($elapsed, 3),
                'estimated_context_tokens' => $packet->estimatedTokens,
                'estimated_payload_tokens' => $packet->metrics['estimated_payload_tokens'] ?? null,
                'budget' => (int) $parameters['budget'],
                'within_budget' => $packet->estimatedTokens <= (int) $parameters['budget'],
                'truncated' => $packet->truncated,
                'expected_hits' => $hits,
                'missing' => array_values(array_diff($expected, $hits)),
                'full_source_estimated_tokens' => $fullSource,
            ];
            $recall += $scenarioRecall;
            $packetRecallTotal += $packetRecall;
            $precision += $scenarioPrecision;
            $mrr += $rank === null ? 0.0 : 1 / $rank;
            $latency += $elapsed;
            $context += $packet->estimatedTokens;
            $counted++;
        }
        $divisor = max(1, $counted);

        return [
            'k' => $k,
            'scenarios' => $counted,
            'mean_recall_at_k' => round($recall / $divisor, 4),
            'mean_recall_in_packet' => round($packetRecallTotal / $divisor, 4),
            'mean_precision_at_k' => round($precision / $divisor, 4),
            'mean_reciprocal_rank' => round($mrr / $divisor, 4),
            'mean_latency_ms' => round($latency / $divisor, 3),
            'mean_estimated_context_tokens' => (int) round($context / $divisor),
            'token_basis' => 'estimated_local_characters',
            'provider_reported_tokens' => null,
            'results' => $results,
        ];
    }

    /** @param list<array<string, mixed>> $sources */
    private function hit(string $expected, array $sources): bool
    {
        foreach ($sources as $source) {
            if ($this->sourceMatches($expected, $source)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source */
    private function sourceMatches(string $expected, array $source): bool
    {
        foreach (['symbol', 'file', 'node_key'] as $field) {
            $identity = strtolower(str_replace('\\', '/', (string) ($source[$field] ?? '')));
            if ($identity !== '' && ($identity === $expected || str_starts_with($identity, $expected.'::') || str_starts_with($identity, $expected.'/') || str_ends_with($identity, '/'.$expected) || str_ends_with($identity, $expected))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $files */
    private function sourceTokens(array $files): int
    {
        $characters = 0;
        $base = function_exists('base_path') ? base_path() : '';
        foreach ($files as $file) {
            $path = $base.'/'.ltrim(str_replace('\\', '/', $file), '/');
            if (is_file($path)) {
                $characters += strlen((string) file_get_contents($path));
            }
        }

        return (int) ceil($characters / $this->tokens->charsPerToken());
    }
}
