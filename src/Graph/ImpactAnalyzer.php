<?php

namespace ProjectMemory\Graph;

use ProjectMemory\Data\ImpactFinding;
use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiModule;
use ProjectMemory\Support\NodeKeys;

class ImpactAnalyzer
{
    /**
     * @return array{subject: ?array<string, mixed>, findings: list<ImpactFinding>, truncated: bool, warning: ?string}
     */
    public function analyze(string $target, ?int $maxDepth = null, ?int $maxNodes = null): array
    {
        $maxDepth = max(0, min(10, $maxDepth ?? (int) config('project-memory.impact.max_depth', 2)));
        $maxNodes = max(0, min(1000, $maxNodes ?? (int) config('project-memory.impact.max_nodes', 30)));
        $resolved = $this->resolve($target);

        if ($resolved['error'] !== null) {
            return [
                'subject' => null,
                'findings' => [],
                'truncated' => false,
                'warning' => $resolved['error'],
            ];
        }

        if ($resolved['module'] instanceof AiModule && ! $resolved['node'] instanceof AiCodeNode) {
            return $this->moduleFindings($resolved['module'], $maxNodes);
        }

        /** @var AiCodeNode $origin */
        $origin = $resolved['node'];
        $findings = [];
        $visited = [$origin->id => true];
        $frontier = [$origin->id => $origin];
        $pathConfidence = [$origin->id => 1.0];
        $paths = [$origin->id => [$origin->node_key]];
        $truncated = false;

        // One bounded query per breadth-first level, rather than one per node.
        // A change propagates to dependents. Walking a dependency in the other
        // direction would report unrelated callers of that dependency as impact.
        for ($depth = 0; $frontier !== [] && $depth < $maxDepth; $depth++) {
            $ids = array_keys($frontier);
            $visitedIds = array_keys($visited);
            $edgeBudget = max(32, ($maxNodes - count($findings)) * 4);
            $fetch = max($edgeBudget, 200);
            $containers = array_keys(array_filter($frontier, fn (AiCodeNode $node) => $node->node_type === 'file' || in_array($node->node_type, ['class', 'interface', 'trait', 'enum', 'event', 'job', 'listener', 'policy'], true)));
            $edges = AiCodeEdge::query()
                ->where(function ($query) use ($ids, $containers, $visitedIds) {
                    $query->where(function ($query) use ($ids, $visitedIds) {
                        $query->whereIn('target_node_id', $ids)->whereNotIn('relationship', ['defines', 'contains'])->whereNotIn('source_node_id', $visitedIds);
                    });
                    if ($containers !== []) {
                        $query->orWhere(function ($query) use ($containers, $visitedIds) {
                            $query->whereIn('source_node_id', $containers)->whereIn('relationship', ['defines', 'contains'])->whereNotIn('target_node_id', $visitedIds);
                        });
                    }
                })
                ->with(['source', 'target'])
                ->orderByRaw("CASE WHEN relationship IN ('extends', 'implements') THEN 0 ELSE 1 END")
                ->orderByDesc('confidence')
                ->orderBy('id')
                ->limit($fetch + 1)
                ->get();
            if ($edges->count() > $fetch) {
                $truncated = true;
                $edges = $edges->take($fetch);
            }
            $declarations = [];
            $declarationHashes = $edges->pluck('declaration_path_hash')->filter()->unique()->values()->all();
            if ($declarationHashes !== []) {
                $declarations = AiCodeNode::query()->where('node_type', 'file')->whereIn('path_hash', $declarationHashes)->pluck('node_key', 'path_hash')->all();
            }

            $next = [];
            foreach ($edges as $edge) {
                $incoming = ! in_array($edge->relationship, ['defines', 'contains'], true);
                $parentId = $incoming ? $edge->target_node_id : $edge->source_node_id;
                $other = $incoming ? $edge->source : $edge->target;

                if (! $other instanceof AiCodeNode || isset($visited[$other->id])) {
                    continue;
                }

                $priority = in_array($edge->relationship, ['extends', 'implements'], true);
                if (count($findings) >= $maxNodes && ! $priority) {
                    $truncated = true;

                    continue;
                }
                if (count($findings) >= 200) {
                    $truncated = true;
                    break 2;
                }

                $visited[$other->id] = true;
                $confidence = min($pathConfidence[$parentId] ?? 1.0, (float) $edge->confidence);
                $pathConfidence[$other->id] = $confidence;
                $declaration = $edge->declaration_path_hash !== null
                    ? ($declarations[$edge->declaration_path_hash] ?? 'missing-declaration:'.$edge->declaration_path_hash)
                    : null;
                $paths[$other->id] = array_values(array_unique(array_filter([...($paths[$parentId] ?? []), ...($edge->metadata['resolution_path'] ?? []), $declaration, $other->node_key])));
                $findings[] = $this->finding($other, $edge, $incoming, $depth + 1, $confidence, $paths[$other->id]);
                $next[$other->id] = $other;
                if (! $priority && count($findings) >= $maxNodes) {
                    // The general cap is full. Direct subclasses already collected in this
                    // batch stay; further ordinary edges are skipped below.
                    $truncated = true;
                }
                if (count($findings) >= 200) {
                    $truncated = true;
                    break 2;
                }
            }
            $frontier = $next;
        }

        return [
            'subject' => $this->subject($origin),
            'findings' => $findings,
            'truncated' => $truncated,
            'warning' => 'Impact follows indexed dependents and container members to depth '.$maxDepth.'. Dynamic calls, inheritance overrides and runtime container bindings may be absent. Risk labels are heuristics; verify live source before acting.',
        ];
    }

    /**
     * @return array{node: ?AiCodeNode, module: ?AiModule, error: ?string}
     */
    private function resolve(string $target): array
    {
        $target = trim($target);
        $canonical = preg_replace('/^(?:interface|trait|enum|event|job|listener|policy):/', 'class:', $target) ?? $target;
        $node = AiCodeNode::query()->where('node_key', NodeKeys::normalize($canonical))->first();
        if ($node === null) {
            $matches = AiCodeNode::query()->where('symbol_name', $target)->orderByRaw('CASE WHEN file_path IS NULL THEN 1 ELSE 0 END')->limit(6)->get();
            $declared = $matches->filter(fn (AiCodeNode $match) => ! ($match->metadata['stub'] ?? false));
            $matches = $declared->isNotEmpty() ? $declared : $matches;
            if ($matches->count() > 1) {
                return ['node' => null, 'module' => null, 'error' => 'Multiple indexed declarations match ['.$target.']. Use a node key.'];
            }
            $node = $matches->first();
        }
        $node ??= AiCodeNode::query()->where('node_type', 'file')->where('file_path', str_replace('\\', '/', $target))->first();

        if ($node instanceof AiCodeNode) {
            return ['node' => $node, 'module' => null, 'error' => null];
        }

        $module = AiModule::query()->where('key', $target)->orWhere('name', $target)->first();
        if ($module instanceof AiModule) {
            return ['node' => null, 'module' => $module, 'error' => null];
        }

        $candidates = AiCodeNode::query()
            ->where('symbol_name', 'like', '%'.$this->like($target).'%')
            ->limit(5)
            ->pluck('symbol_name')
            ->filter()
            ->values()
            ->all();

        if (count($candidates) === 1) {
            return [
                'node' => AiCodeNode::query()->where('symbol_name', $candidates[0])->first(),
                'module' => null,
                'error' => null,
            ];
        }

        $hint = $candidates === []
            ? 'No indexed symbol matches ['.$target.']. Inspect the source or run php artisan ai:sync.'
            : 'Multiple symbols match ['.$target.']. Inspect one of: '.implode(', ', $candidates);

        return ['node' => null, 'module' => null, 'error' => $hint];
    }

    /**
     * @return array{subject: array<string, mixed>, findings: list<ImpactFinding>, truncated: bool, warning: string}
     */
    private function moduleFindings(AiModule $module, int $maxNodes): array
    {
        $nodes = AiCodeNode::query()
            ->where('module_id', $module->id)
            ->where('node_type', '!=', 'file')
            ->orderBy('node_type')
            ->limit($maxNodes + 1)
            ->get();
        $truncated = $nodes->count() > $maxNodes;
        $findings = [];

        foreach ($nodes->take($maxNodes) as $node) {
            $findings[] = new ImpactFinding(
                $node->node_key,
                (string) ($node->symbol_name ?: $node->node_key),
                $node->node_type,
                $node->file_path,
                'contains',
                'outgoing',
                'Symbol belongs to module '.$module->key.'.',
                1.0,
                $this->risk($node, 'contains'),
                1,
            );
        }

        return [
            'subject' => [
                'kind' => 'module',
                'key' => $module->key,
                'name' => $module->name,
                'root_path' => $module->root_path,
            ],
            'findings' => $findings,
            'truncated' => $truncated,
            'warning' => 'Module impact lists indexed members only. It is not a complete runtime call graph. Inspect source for dynamic Laravel behavior.',
        ];
    }

    private function finding(AiCodeNode $node, AiCodeEdge $edge, bool $incoming, int $distance, ?float $pathConfidence = null, array $pathNodeKeys = []): ImpactFinding
    {
        $direction = $incoming ? 'incoming' : 'outgoing';
        $stub = (bool) ($node->metadata['stub'] ?? false);
        $confidence = min($pathConfidence ?? 1.0, (float) $edge->confidence, $stub ? 0.5 : 1.0);

        return new ImpactFinding(
            $node->node_key,
            (string) ($node->symbol_name ?: $node->node_key),
            $node->node_type,
            $node->file_path,
            $edge->relationship,
            $direction,
            ucfirst($direction).' '.$edge->relationship.' relationship'.($confidence < 0.8 ? ' (resolution is uncertain)' : '').($stub ? '; declaration is not indexed' : '').'.',
            $confidence,
            $this->risk($node, $edge->relationship),
            $distance,
            $pathNodeKeys,
        );
    }

    private function risk(AiCodeNode $node, string $relationship): string
    {
        if (in_array($node->node_type, ['route', 'policy', 'migration', 'table'], true)) {
            return 'high';
        }

        if (in_array($node->node_type, ['listener', 'event', 'job', 'blade_view', 'blade_component'], true) || $relationship === 'calls') {
            return 'medium';
        }

        if (is_string($node->file_path) && str_starts_with($node->file_path, 'tests/')) {
            return 'low';
        }

        return 'low';
    }

    /**
     * @return array<string, mixed>
     */
    private function subject(AiCodeNode $node): array
    {
        return [
            'kind' => 'node',
            'node_key' => $node->node_key,
            'symbol' => $node->symbol_name,
            'type' => $node->node_type,
            'file' => $node->file_path,
            'lines' => [$node->start_line, $node->end_line],
            'summary' => $node->summary,
        ];
    }

    private function like(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }
}
