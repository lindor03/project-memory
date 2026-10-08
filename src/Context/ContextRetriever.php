<?php

namespace ProjectMemory\Context;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use ProjectMemory\Data\ContextItem;
use ProjectMemory\Data\ContextPacket;
use ProjectMemory\Graph\ImpactAnalyzer;
use ProjectMemory\Indexing\FileHasher;
use ProjectMemory\Models\AiAgentRun;
use ProjectMemory\Models\AiChangeSet;
use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiIndexRun;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Models\AiModule;
use ProjectMemory\Support\NodeKeys;

class ContextRetriever
{
    private SourceFreshness $freshness;

    private int $candidateLimit = 160;

    private array $limitReasons = [];

    public function __construct(
        private readonly ContextRanker $ranker,
        private readonly ContextBudgeter $budgeter,
        private readonly ContextFormatter $formatter,
        private readonly ImpactAnalyzer $impact,
        FileHasher $hasher,
        ?SourceFreshness $freshness = null,
    ) {
        $this->freshness = $freshness ?? new SourceFreshness($hasher);
    }

    public function retrieve(string $operation, array $parameters = []): ContextPacket
    {
        $started = hrtime(true);
        $maxBudget = max(0, min(100000, (int) config('project-memory.context.max_budget', 32000)));
        $budget = max(0, min($maxBudget, (int) ($parameters['budget'] ?? config('project-memory.context.budget', 4000))));
        $this->candidateLimit = max(12, min(500, (int) config('project-memory.context.candidate_limit', 160), (int) ceil($budget / 20)));
        $this->limitReasons = [];
        $this->freshness->reset();
        $includeStale = (bool) ($parameters['include_stale'] ?? false);
        $warnings = [];
        $stale = false;
        $queryCount = 0;
        $queryMs = 0.0;
        $connection = (new AiCodeNode)->getConnection();
        $dispatcher = $connection->getEventDispatcher();
        if ($dispatcher !== null) {
            $measuredDispatcher = clone $dispatcher;
            $measuredDispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($connection, &$queryCount, &$queryMs) {
                if ($event->connection === $connection) {
                    $queryCount++;
                    $queryMs += $event->time;
                }
            });
            $connection->setEventDispatcher($measuredDispatcher);
        }

        try {
            $items = match ($operation) {
                'project_overview' => $this->overviewItems($warnings, $stale),
                'module_context' => $this->moduleItems((string) ($parameters['module'] ?? ''), (string) ($parameters['query'] ?? ''), $includeStale, $warnings, $stale),
                'symbol_lookup' => $this->symbolItems((string) ($parameters['symbol'] ?? ''), $includeStale, $warnings, $stale),
                'impact_analysis' => $this->impactItems($parameters, $includeStale, $warnings, $stale),
                'change_history' => $this->historyItems((int) ($parameters['limit'] ?? 10)),
                'architecture_rules' => $this->ruleItems($includeStale, $warnings, $stale),
                default => [],
            };
            if (! in_array($operation, ['project_overview', 'module_context', 'symbol_lookup', 'impact_analysis', 'change_history', 'architecture_rules'], true)) {
                $warnings[] = 'Unknown context operation ['.$operation.'].';
            }
        } finally {
            if ($dispatcher !== null) {
                $connection->setEventDispatcher($dispatcher);
            }
        }

        $candidateCount = count($items);
        $items = $this->ranker->sort($items);
        $warnings = array_values(array_unique($warnings));
        if ($this->limitReasons !== []) {
            $warnings[] = 'Candidate retrieval was bounded; refine the query or request a larger budget for more context.';
        }
        $header = $this->formatter->header(str_replace('_', ' ', $operation), $warnings);
        $constrained = $this->budgeter->constrain($header, $items, $budget);
        $metrics = [
            'budget_tokens' => $budget,
            'budget_scope' => 'context_text',
            'candidate_count' => $candidateCount,
            'deduplicated_count' => $candidateCount - count($items),
            'selected_count' => count($constrained['sources']),
            'omitted_count' => $constrained['omitted_count'],
            'compacted_count' => $constrained['compacted_count'],
            'query_count' => $dispatcher === null ? null : $queryCount,
            'query_time_ms' => $dispatcher === null ? null : round($queryMs, 3),
            'candidate_limit' => $this->candidateLimit,
            'truncation_reasons' => array_values(array_unique(array_merge($this->limitReasons, $constrained['truncated'] ? ['token_budget'] : []))),
        ] + $this->freshness->metrics();
        $packet = new ContextPacket(
            $operation,
            $constrained['text'],
            $constrained['estimated_tokens'],
            $constrained['truncated'] || $this->limitReasons !== [],
            $stale,
            $warnings,
            $constrained['sources'],
            strlen($constrained['text']),
            $metrics,
        );
        $reported = is_array($parameters['reported_tokens'] ?? null) ? $parameters['reported_tokens'] : [];
        $packet->metrics['latency_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
        $packet->metrics['token_basis'] = 'estimated_local_characters';
        $packet->metrics['provider_reported_input_tokens'] = is_int($reported['input'] ?? null) ? $reported['input'] : null;
        $packet->metrics['provider_reported_output_tokens'] = is_int($reported['output'] ?? null) ? $reported['output'] : null;
        $packet->metrics['provider_reported_cached_tokens'] = is_int($reported['cached'] ?? null) ? $reported['cached'] : null;
        $this->estimatePayload($packet);
        try {
            $this->record($packet, $parameters, (int) $packet->metrics['latency_ms']);
        } catch (\Throwable $exception) {
            $packet->warnings[] = 'Retrieval succeeded, but usage telemetry could not be recorded. Check the ai_agent_runs schema and connection.';
            logger()->warning('Project memory usage telemetry could not be persisted.', ['exception_class' => $exception::class]);
            $this->estimatePayload($packet);
        }

        return $packet;
    }

    /** @return list<ContextItem> */
    private function overviewItems(array &$warnings, bool &$stale): array
    {
        $last = AiIndexRun::query()->latest('id')->first();
        $nodeCount = AiCodeNode::query()->count();
        if ($nodeCount === 0) {
            $warnings[] = 'The index is empty. Run php artisan ai:scan, or inspect the repository directly.';
        }
        if ($last !== null && $last->status !== 'completed') {
            $warnings[] = 'The latest indexing run did not complete; source inspection is required.';
        }
        $items = [new ContextItem(
            'overview-counts', 180,
            sprintf('Nodes: %d. Edges: %d. Modules: %d. Active stored rules: %d. Last run: %s.', $nodeCount, AiCodeEdge::query()->count(), AiModule::query()->count(), AiKnowledge::query()->where('state', AiKnowledge::STATE_ACTIVE)->count(), $last?->status ?? 'never'),
            ['type' => 'summary', 'authority' => 'index'],
        )];
        $counts = AiCodeNode::query()->selectRaw('module_id, COUNT(*) AS total')->where('node_type', '!=', 'file')->groupBy('module_id')->pluck('total', 'module_id');
        $modules = AiModule::query()->orderBy('key')->limit($this->candidateLimit + 1)->get();
        if ($modules->count() > $this->candidateLimit) {
            $this->limitReasons[] = 'module_candidates';
        }
        foreach ($modules->take($this->candidateLimit) as $module) {
            $text = 'Module '.$module->key.' ('.$module->name.') root '.$module->root_path.' symbols '.($counts[$module->id] ?? 0).'.';
            $items[] = new ContextItem('module:'.$module->key, 80, $text.($module->summary ? ' Advisory stored summary: '.$module->summary : ''), ['type' => 'module', 'key' => $module->key, 'path' => $module->root_path, 'authority' => 'index', 'summary_verified' => false], $text);
        }
        foreach ($this->usableKnowledge(false, $warnings, $stale) as $rule) {
            $items[] = $this->knowledgeItem($rule, 120);
        }

        return $items;
    }

    /** @return list<ContextItem> */
    private function moduleItems(string $name, string $query, bool $includeStale, array &$warnings, bool &$stale): array
    {
        $module = AiModule::query()->where('key', $name)->orWhere('name', $name)->first();
        if (! $module instanceof AiModule) {
            $warnings[] = 'No indexed module matches ['.$name.']. Run php artisan ai:modules or inspect the source.';

            return [];
        }
        $text = 'Module '.$module->key.' '.$module->name.' root '.$module->root_path.'.';
        $items = [new ContextItem('module-header:'.$module->key, 180, $text.' '.($module->summary ? 'Advisory stored summary: '.$module->summary : 'No stored summary.'), ['type' => 'module', 'key' => $module->key, 'path' => $module->root_path, 'authority' => 'index', 'summary_verified' => false], $text)];
        $nodeQuery = AiCodeNode::query()->where('module_id', $module->id)->where('node_type', '!=', 'file');
        if (trim($query) !== '') {
            $terms = $this->ranker->terms($query);
            if ($terms !== []) {
                $nodeQuery->where(function ($builder) use ($terms) {
                    foreach ($terms as $term) {
                        foreach (['symbol_name', 'file_path', 'summary'] as $field) {
                            $builder->orWhereRaw('LOWER('.$field.') LIKE ? ESCAPE \'!\'', ['%'.$this->like($term).'%']);
                        }
                    }
                });
                $scores = [];
                $bindings = [];
                foreach ($terms as $term) {
                    foreach (['symbol_name' => 3, 'summary' => 2, 'file_path' => 1] as $field => $weight) {
                        $scores[] = '(CASE WHEN LOWER('.$field.') LIKE ? ESCAPE \'!\' THEN '.$weight.' ELSE 0 END)';
                        $bindings[] = '%'.$this->like($term).'%';
                    }
                }
                // Rank before LIMIT so broad path matches do not crowd out
                // symbol and signature evidence from later alphabetic rows.
                $nodeQuery->orderByRaw('('.implode(' + ', $scores).') DESC', $bindings);
            }
        }
        $nodes = $nodeQuery->orderBy('node_type')->orderBy('symbol_name')->limit($this->candidateLimit + 1)->get();
        if ($nodes->count() > $this->candidateLimit) {
            $this->limitReasons[] = 'symbol_candidates';
        }
        $nodes = $nodes->take($this->candidateLimit);
        $this->freshness->prime($nodes);
        foreach ($nodes as $node) {
            $fresh = $this->freshness->node($node);
            $stale = $stale || ! $fresh;
            $score = ($node->node_type === 'method' ? 80 : 90) + $this->ranker->relevance($query, [(string) $node->symbol_name, (string) $node->file_path, (string) $node->summary]);
            $items[] = $this->nodeItem($node, $fresh, $fresh ? $score : 35);
        }
        if ($stale) {
            $warnings[] = 'Some indexed symbols changed or cannot be resolved. Stored summaries were withheld; read their source files and run php artisan ai:sync.';
        }
        if (trim($query) !== '' && $nodes->isNotEmpty()) {
            $seeds = $nodes->sortByDesc(fn ($node) => $this->ranker->relevance($query, [(string) $node->symbol_name, (string) $node->summary]))->take(8);
            $items = array_merge($items, $this->edgeItems($seeds->all(), $includeStale, $warnings, $stale));
        }
        foreach ($this->usableKnowledge($includeStale, $warnings, $stale, $module) as $rule) {
            $items[] = $this->knowledgeItem($rule, 110 + $this->ranker->relevance($query, [$rule->title, $rule->body]));
        }

        return $items;
    }

    /** @return list<ContextItem> */
    private function symbolItems(string $symbol, bool $includeStale, array &$warnings, bool &$stale): array
    {
        $symbol = ltrim(trim($symbol), '\\');
        if ($symbol === '') {
            $warnings[] = 'A non-empty symbol or node key is required.';

            return [];
        }
        $canonical = preg_replace('/^(?:interface|trait|enum|event|job|listener|policy):/', 'class:', $symbol) ?? $symbol;
        $key = NodeKeys::normalize($canonical);
        $matches = AiCodeNode::query()->where('node_key', $key)->orWhere('symbol_name', $symbol)->orderByRaw('CASE WHEN node_key = ? THEN 0 ELSE 1 END', [$key])->limit(6)->get();
        if (! $matches->contains('node_key', $key)) {
            $declared = $matches->filter(fn ($node) => ! ($node->metadata['stub'] ?? false));
            $matches = $declared->isNotEmpty() ? $declared : $matches;
        } else {
            $matches = $matches->where('node_key', $key);
        }
        if ($matches->isEmpty()) {
            $matches = AiCodeNode::query()->where('node_type', '!=', 'file')->whereRaw('LOWER(symbol_name) LIKE ? ESCAPE \'!\'', ['%'.$this->like(mb_strtolower($symbol)).'%'])->orderBy('symbol_name')->limit(6)->get();
        }
        if ($matches->isEmpty()) {
            $warnings[] = 'No indexed symbol matches ['.$symbol.']. Inspect the source before editing.';

            return [];
        }
        $this->freshness->prime($matches);
        if ($matches->count() > 1) {
            $warnings[] = 'Multiple indexed symbols match ['.$symbol.']. Edges for each candidate are included. Use one of the returned node keys to resolve the ambiguity.';
            $items = $matches->map(function ($node) use (&$stale) {
                $fresh = $this->freshness->node($node);
                $stale = $stale || ! $fresh;

                return $this->nodeItem($node, $fresh, 160);
            })->all();

            return array_merge($items, $this->edgeItems($matches->all(), $includeStale, $warnings, $stale), $this->viewHopItems($matches->all(), $includeStale, $warnings, $stale));
        }
        $node = $matches->first();
        $fresh = $this->freshness->node($node);
        if (! $fresh) {
            $stale = true;
            $warnings[] = 'Indexed symbol ['.$node->symbol_name.'] is stale or unresolved. Read '.($node->file_path ?: 'the declaration source').' before relying on this memory.';
        }

        $seeds = [$node];
        if ($node->node_type === 'method' && is_string($node->symbol_name) && str_contains($node->symbol_name, '::')) {
            $owner = AiCodeNode::query()->where('node_key', NodeKeys::normalize('class:'.explode('::', $node->symbol_name, 2)[0]))->first();
            if ($owner instanceof AiCodeNode) {
                $seeds[] = $owner;
            }
        }

        return array_merge(
            [$this->nodeItem($node, $fresh, 180)],
            $this->edgeItems($seeds, $includeStale, $warnings, $stale, $node->id),
            $this->viewHopItems([$node], $includeStale, $warnings, $stale),
        );
    }

    /** @param list<AiCodeNode> $seeds @return list<ContextItem> */
    private function viewHopItems(array $seeds, bool $includeStale, array &$warnings, bool &$stale): array
    {
        $frontier = array_values(array_filter($seeds, fn ($node) => in_array($node->node_type, ['blade_view', 'blade_component'], true)));
        if ($frontier === []) {
            return [];
        }
        $seen = [];
        foreach ($frontier as $node) {
            $seen[$node->id] = true;
        }
        $items = [];
        $withheld = 0;
        for ($depth = 1; $depth <= 4 && $frontier !== []; $depth++) {
            $ids = array_map(fn ($node) => $node->id, $frontier);
            $edges = AiCodeEdge::query()
                ->whereIn('source_node_id', $ids)
                ->whereIn('relationship', ['renders_view', 'uses_component'])
                ->with('target')
                ->orderBy('id')
                ->limit(33)
                ->get();
            if ($edges->count() > 32) {
                $this->limitReasons[] = 'blade_include_hops';
                $edges = $edges->take(32);
            }
            $this->freshness->prime($edges->pluck('target')->filter());
            $next = [];
            foreach ($edges as $edge) {
                $target = $edge->target;
                if (! $target instanceof AiCodeNode || isset($seen[$target->id])) {
                    continue;
                }
                $seen[$target->id] = true;
                $fresh = $this->freshness->node($target);
                $stale = $stale || ! $fresh;
                if (! $fresh && ! $includeStale) {
                    $withheld++;

                    continue;
                }
                $items[] = new ContextItem(
                    'view-hop:'.$target->node_key,
                    $fresh ? max(120, 170 - ($depth * 8)) : 20,
                    ($fresh ? '' : '[stale graph] ').'nested '.$edge->relationship.' '.$target->symbol_name.' depth '.$depth.' file '.($target->file_path ?: 'unresolved'),
                    [
                        'type' => $target->node_type,
                        'symbol' => $target->symbol_name,
                        'file' => $target->file_path,
                        'node_key' => $target->node_key,
                        'relationship' => $edge->relationship,
                        'depth' => $depth,
                        'fresh' => $fresh,
                    ],
                );
                $next[] = $target;
            }
            $frontier = $next;
        }
        if ($withheld > 0) {
            $warnings[] = $withheld.' nested Blade includes were withheld because their source changed; run php artisan ai:sync.';
        }

        return $items;
    }

    /** @param list<AiCodeNode> $seeds @return list<ContextItem> */
    private function edgeItems(array $seeds, bool $includeStale, array &$warnings, bool &$stale, ?int $primaryId = null): array
    {
        $ids = array_map(fn ($node) => $node->id, $seeds);
        $limit = min(80, $this->candidateLimit);
        $edges = AiCodeEdge::query()->whereNotIn('relationship', ['defines', 'contains'])->where(function ($query) use ($ids) {
            $query->whereIn('source_node_id', $ids)->orWhereIn('target_node_id', $ids);
        })->with(['source', 'target'])->orderByDesc('confidence')->orderBy('id')->limit($limit + 1)->get();
        if ($edges->count() > $limit) {
            $this->limitReasons[] = 'graph_neighbors';
        }
        $edges = $edges->take($limit);
        $this->freshness->prime($edges->flatMap(fn ($edge) => [$edge->source, $edge->target])->filter());
        $declarationHashes = $edges->pluck('declaration_path_hash')->filter()->unique();
        $declarations = $declarationHashes->isEmpty() ? collect() : AiCodeNode::query()->where('node_type', 'file')->whereIn('path_hash', $declarationHashes->all())->get()->keyBy('path_hash');
        $this->freshness->prime($declarations);
        $resolutionKeys = $edges->flatMap(fn ($edge) => is_array($edge->metadata['resolution_path'] ?? null) ? $edge->metadata['resolution_path'] : [])->filter(fn ($key) => is_string($key))->unique();
        $resolutionNodes = collect();
        foreach ($resolutionKeys->chunk(500) as $chunk) {
            $resolutionNodes = $resolutionNodes->merge(AiCodeNode::query()->whereIn('node_key', $chunk->all())->get());
        }
        $resolutionNodes = $resolutionNodes->keyBy('node_key');
        $this->freshness->prime($resolutionNodes);
        $items = [];
        $withheld = 0;
        foreach ($edges as $edge) {
            if (! $edge->source instanceof AiCodeNode || ! $edge->target instanceof AiCodeNode) {
                continue;
            }
            $incoming = in_array($edge->target_node_id, $ids, true);
            $other = $incoming ? $edge->source : $edge->target;
            $resolved = ! ($edge->target->metadata['stub'] ?? false);
            $declaration = $declarations[$edge->declaration_path_hash] ?? null;
            $fresh = $declaration instanceof AiCodeNode && $this->freshness->node($declaration)
                && $this->freshness->node($edge->source) && (! $resolved || $this->freshness->node($edge->target));
            foreach (is_array($edge->metadata['resolution_path'] ?? null) ? $edge->metadata['resolution_path'] : [] as $key) {
                $resolutionNode = is_string($key) ? ($resolutionNodes[$key] ?? null) : null;
                if (! $resolutionNode instanceof AiCodeNode || ! $this->freshness->node($resolutionNode)) {
                    $fresh = false;
                    break;
                }
            }
            if (! $fresh) {
                $stale = true;
                if (! $includeStale) {
                    $withheld++;

                    continue;
                }
            }
            $direction = $incoming ? 'incoming' : 'outgoing';
            $resolutionState = is_string($edge->metadata['resolution_state'] ?? null) ? $edge->metadata['resolution_state'] : null;
            $owned = $primaryId !== null && ($edge->source_node_id === $primaryId || $edge->target_node_id === $primaryId);
            $structural = in_array($edge->relationship, ['renders_view', 'uses_component', 'uses_model', 'binds', 'uses_table'], true) ? 15 : 0;
            $items[] = new ContextItem('edge:'.$edge->id, ($fresh ? 70 + (int) round(20 * (float) $edge->confidence) : 20) + ($owned ? 40 : 0) + $structural, ($fresh ? '' : '[stale graph] ').$direction.' '.$edge->relationship.' '.$other->symbol_name.' confidence '.$edge->confidence.($resolutionState !== null ? ' state '.$resolutionState : '').' file '.($other->file_path ?: 'unresolved').($resolved ? '' : ' (declaration unresolved)'), [
                'type' => 'edge', 'relationship' => $edge->relationship, 'confidence' => (float) $edge->confidence,
                'resolution_state' => $resolutionState,
                'file' => $other->file_path, 'symbol' => $other->symbol_name, 'fresh' => $fresh, 'resolved' => $resolved,
                'source_node_key' => $edge->source->node_key, 'target_node_key' => $edge->target->node_key,
                'declaration_file' => $declaration?->file_path,
                'resolution_path' => $edge->metadata['resolution_path'] ?? [],
            ]);
        }
        if ($withheld > 0) {
            $warnings[] = $withheld.' graph relationships were withheld because their source evidence changed; run php artisan ai:sync.';
        }

        return $items;
    }

    /** @return list<ContextItem> */
    private function impactItems(array $parameters, bool $includeStale, array &$warnings, bool &$stale): array
    {
        $result = $this->impact->analyze((string) ($parameters['target'] ?? ''), isset($parameters['depth']) ? (int) $parameters['depth'] : null, isset($parameters['max_nodes']) ? (int) $parameters['max_nodes'] : null);
        if ($result['warning'] !== null) {
            $warnings[] = $result['warning'];
        }
        if ($result['truncated']) {
            $this->limitReasons[] = 'impact_traversal';
        }
        $keys = array_map(fn ($finding) => $finding->nodeKey, $result['findings']);
        foreach ($result['findings'] as $finding) {
            $keys = array_merge($keys, $finding->pathNodeKeys);
        }
        if (isset($result['subject']['node_key'])) {
            $keys[] = $result['subject']['node_key'];
        }
        $nodes = AiCodeNode::query()->whereIn('node_key', $keys)->get()->keyBy('node_key');
        $this->freshness->prime($nodes);
        $items = [];
        $subjectFresh = true;
        if ($result['subject'] !== null) {
            $subject = $result['subject'];
            if (isset($subject['node_key'])) {
                $origin = $nodes[$subject['node_key']] ?? null;
                $subjectFresh = $origin instanceof AiCodeNode && $this->freshness->node($origin);
                if (! $subjectFresh) {
                    $stale = true;
                    $subject['summary'] = null;
                    $warnings[] = 'The indexed impact subject changed; stored summaries and dependent relationships require source verification.';
                }
                $subject['fresh'] = $subjectFresh;
            }
            $items[] = new ContextItem('impact-subject', 180, 'Subject '.json_encode($subject, JSON_UNESCAPED_SLASHES), ['type' => 'subject'] + $subject);
        }
        $withheld = 0;
        foreach ($result['findings'] as $finding) {
            $node = $nodes[$finding->nodeKey] ?? null;
            $fresh = $subjectFresh && $node instanceof AiCodeNode && $this->freshness->node($node);
            foreach ($finding->pathNodeKeys as $pathKey) {
                $pathNode = $nodes[$pathKey] ?? null;
                if (! $pathNode instanceof AiCodeNode || ! $this->freshness->node($pathNode)) {
                    $fresh = false;
                    break;
                }
            }
            $stale = $stale || ! $fresh;
            if (! $fresh && ! $includeStale) {
                $withheld++;

                continue;
            }
            $inheritance = in_array($finding->relationship, ['extends', 'implements'], true) ? 35 : 0;
            $items[] = new ContextItem($finding->nodeKey.':'.$finding->relationship.':'.$finding->direction, $fresh ? max(1, 100 - ($finding->distance * 15) + $inheritance) : 20, ($fresh ? '' : '[stale graph] ').sprintf('d%d %s %s %s confidence %.2f risk %s file %s. %s', $finding->distance, $finding->direction, $finding->relationship, $finding->symbol, $finding->confidence, $finding->risk, $finding->file ?: 'unresolved', $finding->reason), $finding->toArray() + ['fresh' => $fresh]);
        }
        if ($withheld > 0) {
            $warnings[] = $withheld.' impact findings were withheld because indexed source evidence is stale or unresolved; inspect source and run php artisan ai:sync.';
        }

        return $items;
    }

    /** @return list<ContextItem> */
    private function historyItems(int $limit): array
    {
        $limit = max(0, min(100, $this->candidateLimit, $limit));
        $items = [];
        foreach (AiChangeSet::query()->latest('id')->limit($limit)->get() as $change) {
            $items[] = new ContextItem('change:'.$change->id, 70, trim('Change #'.$change->id.' '.$change->intent.' validation '.($change->validation_outcome ?: 'unrecorded').' lessons '.($change->lessons ?: 'none')), ['type' => 'change_set', 'id' => $change->id, 'files' => $change->changed_files, 'symbols' => $change->changed_symbols, 'commit' => $change->git_commit]);
        }

        return $items;
    }

    /** @return list<ContextItem> */
    private function ruleItems(bool $includeStale, array &$warnings, bool &$stale): array
    {
        $rules = $this->usableKnowledge($includeStale, $warnings, $stale);
        if ($rules->isEmpty()) {
            $warnings[] = 'No usable architectural rules are stored. Draft suggestions require explicit approval with php artisan ai:learn --approve.';
        }

        return $rules->map(fn ($rule) => $this->knowledgeItem($rule, $rule->state === AiKnowledge::STATE_ACTIVE ? 120 : 30))->all();
    }

    /** @return Collection<int, AiKnowledge> */
    private function usableKnowledge(bool $includeStale, array &$warnings, bool &$stale, ?AiModule $module = null): Collection
    {
        $query = AiKnowledge::query()->where(function ($query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->whereIn('state', $includeStale ? [AiKnowledge::STATE_ACTIVE, AiKnowledge::STATE_STALE] : [AiKnowledge::STATE_ACTIVE]);
        if ($module !== null) {
            $query->where(function ($query) use ($module) {
                $query->whereRaw('LOWER(title) LIKE ? ESCAPE \'!\'', ['%'.$this->like(mb_strtolower($module->key)).'%'])
                    ->orWhereRaw('LOWER(body) LIKE ? ESCAPE \'!\'', ['%'.$this->like(mb_strtolower($module->key)).'%'])
                    ->orWhere(function ($query) {
                        $query->where('kind', 'architectural_rule')->where(function ($query) {
                            $query->whereNull('source_refs')->orWhere('source_refs', '[]');
                        });
                    });
                foreach ($this->modulePaths($module) as $root) {
                    $query->orWhereRaw('source_refs LIKE ? ESCAPE \'!\'', ['%'.$this->like($root).'%'])
                        ->orWhereRaw('source_refs LIKE ? ESCAPE \'!\'', ['%'.$this->like(str_replace('/', '\\/', $root)).'%']);
                }
            });
        }
        $limit = min(500, max(1, (int) config('project-memory.context.knowledge_limit', 160)), $this->candidateLimit);
        $rules = $query->orderByDesc('verified_at')->orderBy('id')->limit($limit + 1)->get();
        if ($rules->count() > $limit) {
            $this->limitReasons[] = 'knowledge_candidates';
        }
        $rules = $rules->take($limit)->filter(fn ($rule) => $module === null || $this->relatedKnowledge($rule, $module));
        $keys = $rules->flatMap(fn ($rule) => array_column($this->knowledgeRefs($rule), 'node_key'))->filter()->unique();
        $nodes = collect();
        foreach ($keys->chunk(500) as $chunk) {
            $nodes = $nodes->merge(AiCodeNode::query()->whereIn('node_key', $chunk->all())->get());
        }
        $nodes = $nodes->keyBy('node_key');
        $this->freshness->prime($nodes);
        $withheld = 0;
        $usable = collect();
        foreach ($rules as $rule) {
            $valid = $rule->state === AiKnowledge::STATE_ACTIVE;
            foreach ($this->knowledgeRefs($rule) as $ref) {
                $key = $ref['node_key'] ?? null;
                $node = $key !== null ? ($nodes[$key] ?? null) : null;
                $expected = $key !== null ? ($rule->source_hashes[$key] ?? null) : null;
                if (! $node instanceof AiCodeNode || ! is_string($expected) || ! hash_equals($expected, (string) $node->content_hash) || ! $this->freshness->node($node)) {
                    $valid = false;
                    break;
                }
                if (isset($ref['source_file_hash']) && $this->freshness->liveHash((string) $node->file_path) !== $ref['source_file_hash']) {
                    $valid = false;
                    break;
                }
            }
            if (! $valid) {
                $stale = true;
                if (! $includeStale) {
                    $withheld++;

                    continue;
                }
                // This is an in-memory advisory state, never a database mutation.
                $rule->state = AiKnowledge::STATE_STALE;
            }
            $usable->push($rule);
        }
        if ($withheld > 0) {
            $warnings[] = $withheld.' stored knowledge records were withheld because live provenance no longer matches; revalidate before approval.';
        }

        return $usable;
    }

    private function relatedKnowledge(AiKnowledge $rule, AiModule $module): bool
    {
        $refs = $this->knowledgeRefs($rule);
        if ($refs === [] && $rule->kind === 'architectural_rule') {
            return true;
        }
        foreach ($this->modulePaths($module) as $root) {
            foreach ($refs as $ref) {
                $path = str_replace('\\', '/', (string) ($ref['file'] ?? ''));
                if ($path === $root || str_starts_with($path, $root.'/')) {
                    return true;
                }
            }
        }

        return $this->ranker->relevance($module->key, [$rule->title, $rule->body]) > 0;
    }

    /** @return list<string> */
    private function modulePaths(AiModule $module): array
    {
        $paths = $module->metadata['paths'] ?? [];
        $paths = is_array($paths) ? $paths : [];
        if ($paths === [] && $module->root_path !== null) {
            $paths[] = $module->root_path;
        }

        return array_values(array_unique(array_filter(array_map(fn ($path) => is_string($path) ? rtrim(str_replace('\\', '/', $path), '/') : '', $paths), fn ($path) => $path !== '')));
    }

    private function knowledgeItem(AiKnowledge $rule, int $score): ContextItem
    {
        $confidence = max(0.0, min(1.0, (float) ($rule->metadata['confidence'] ?? 0.5)));
        $lead = 'Rule ['.$rule->state.'] '.$rule->kind.': '.$rule->title.'.';

        return new ContextItem('knowledge:'.$rule->knowledge_key, $rule->state === AiKnowledge::STATE_STALE ? min(30, $score) : $score + (int) round($confidence * 10), $lead.' '.$rule->body, [
            'type' => 'knowledge', 'key' => $rule->knowledge_key, 'state' => $rule->state, 'refs' => $rule->source_refs,
            'confidence' => $confidence, 'version' => $rule->metadata['version'] ?? 1, 'provenance' => $rule->metadata['provenance'] ?? null,
            'verified_at' => $rule->verified_at?->toIso8601String(), 'authority' => 'approved_advisory',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function knowledgeRefs(AiKnowledge $rule): array
    {
        $refs = $rule->source_refs ?? [];
        if (! is_array($refs)) {
            return [['invalid' => true]];
        }

        return array_values(array_map(fn ($ref) => is_array($ref) ? $ref : ['invalid' => true], $refs));
    }

    private function nodeItem(AiCodeNode $node, bool $fresh, int $score): ContextItem
    {
        $location = $node->file_path !== null
            ? $node->file_path.':'.($fresh ? ($node->start_line ?: '?') : '?')
            : (implode(', ', $this->freshness->paths($node)) ?: 'unresolved');
        $lead = $node->node_type.' '.$node->symbol_name.' at '.$location.'.';
        $text = $fresh ? $lead.' '.($node->summary ?: $node->node_type) : $lead.' Indexed source is stale or unresolved. Read the source; the stored summary was withheld.';

        return new ContextItem($node->node_key, $score, $text, [
            'type' => $node->node_type, 'node_key' => $node->node_key, 'symbol' => $node->symbol_name, 'file' => $node->file_path,
            'start_line' => $fresh ? $node->start_line : null, 'end_line' => $fresh ? $node->end_line : null,
            'fresh' => $fresh, 'content_hash' => $node->content_hash, 'summary' => $fresh ? $node->summary : null,
            'authority' => 'index',
            'source_files' => $node->file_path === null ? $this->freshness->paths($node) : [],
        ], $fresh ? $lead : null);
    }

    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function estimatePayload(ContextPacket $packet): void
    {
        $packet->metrics['estimated_payload_tokens'] = 0;
        // Include the estimate's own field in the serialized size. Its digit
        // count reaches a fixed point without retaining any extra payload.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $payload = json_encode($packet->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                $packet->metrics['estimated_payload_tokens'] = null;

                return;
            }
            $estimated = (int) ceil(strlen($payload) / max(1, (int) config('project-memory.context.chars_per_token', 4)));
            if ($packet->metrics['estimated_payload_tokens'] === $estimated) {
                return;
            }
            $packet->metrics['estimated_payload_tokens'] = $estimated;
        }
    }

    private function record(ContextPacket $packet, array $parameters, int $latency): void
    {
        $reported = is_array($parameters['reported_tokens'] ?? null) ? $parameters['reported_tokens'] : [];
        $hasReported = ($reported['input'] ?? $reported['output'] ?? $reported['cached'] ?? null) !== null;
        AiAgentRun::query()->create([
            'operation' => $packet->operation, 'agent' => $parameters['agent'] ?? null, 'model' => $parameters['model'] ?? null,
            'reported_input_tokens' => $reported['input'] ?? null, 'reported_output_tokens' => $reported['output'] ?? null,
            'reported_cached_tokens' => $reported['cached'] ?? null, 'retrieved_context_size' => $packet->retrievedCharacters,
            'estimated_input_tokens' => $packet->estimatedTokens, 'estimated_output_tokens' => null,
            'token_basis' => $hasReported ? 'mixed' : 'estimated', 'latency_ms' => $latency, 'cost' => null,
            'metadata' => ['truncated' => $packet->truncated, 'stale' => $packet->stale, 'retrieval' => $packet->metrics,
                'note' => 'No model price was applied. Text token estimates use UTF-8 bytes/'.max(1, (int) config('project-memory.context.chars_per_token', 4)).'; payload estimates include JSON provenance.'],
        ]);
    }
}
