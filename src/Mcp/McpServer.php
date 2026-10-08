<?php

namespace ProjectMemory\Mcp;

use ProjectMemory\Context\ContextRetriever;
use ProjectMemory\Context\ContextRouter;
use ProjectMemory\Exceptions\McpWriteForbiddenException;
use ProjectMemory\Indexing\IndexSynchronizer;
use ProjectMemory\Knowledge\KnowledgeStore;
use ProjectMemory\Maintenance\IndexHealth;
use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiIndexRun;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Models\AiModule;
use ProjectMemory\PackageInfo;
use ProjectMemory\Schema\SchemaInstaller;

class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly ContextRetriever $retriever,
        private readonly SchemaInstaller $schema,
        private readonly IndexSynchronizer $synchronizer,
        private readonly KnowledgeStore $knowledge,
        private readonly IndexHealth $health,
        private readonly ContextRouter $router,
    ) {}

    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)
            || (array_key_exists('id', $message) && ! is_int($id) && ! is_string($id) && $id !== null)
            || (isset($message['params']) && ! is_array($message['params']))) {
            return $this->error(null, -32600, 'Invalid JSON-RPC request');
        }
        $method = $message['method'];
        // Notifications have no response, including unknown notification methods.
        if (! array_key_exists('id', $message)) {
            return null;
        }
        $params = $message['params'] ?? [];
        if ($method === 'tools/call') {
            try {
                $this->validateCall($params);
            } catch (\InvalidArgumentException $exception) {
                return $this->error($id, -32602, $exception->getMessage());
            }
        }
        if ($method === 'initialize') {
            $requested = $params['protocolVersion'] ?? null;
            if (! is_string($requested) || $requested === '') {
                return $this->error($id, -32602, 'initialize requires protocolVersion');
            }

            return $this->result($id, [
                'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => (object) ['listChanged' => false]],
                'serverInfo' => ['name' => 'project-memory', 'version' => PackageInfo::VERSION],
                'instructions' => 'Check index_status, then retrieve focused application context before editing. Use context_route to decide between project memory and Laravel Boost. Boost search-docs is the only Laravel documentation search. Source code outranks memory. Inspect cited source whenever stale or confidence is below 0.8. Write tools require explicit confirmation and local configuration.',
            ]);
        }

        return match ($method) {
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->result($id, $this->call($params)),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    /** @return list<array<string, mixed>> */
    public function tools(): array
    {
        $budget = ['type' => 'integer', 'minimum' => 1, 'maximum' => 32000];
        $stale = ['type' => 'boolean'];
        $text = ['type' => 'string', 'minLength' => 1];
        $refs = ['type' => 'array', 'items' => $text, 'maxItems' => 100];
        $definitions = [
            'project_overview' => ['Compact indexed architecture overview.', ['budget' => $budget]],
            'module_context' => ['Focused context for one indexed module; query ranks lexical matches and graph neighbors.', ['module' => $text, 'query' => $text, 'budget' => $budget, 'include_stale' => $stale], ['module']],
            'symbol_lookup' => ['Exact symbol details and relationships; inspect cited live source when stale.', ['symbol' => $text, 'budget' => $budget], ['symbol']],
            'impact_analysis' => ['Bounded callers and dependencies; unresolved analysis is explicitly labelled.', ['target' => $text, 'budget' => $budget, 'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], 'max_nodes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], ['target']],
            'change_history' => ['Recorded development change sets.', ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'budget' => $budget]],
            'architecture_rules' => ['Approved advisory knowledge with source evidence and provenance.', ['budget' => $budget, 'include_stale' => $stale]],
            'index_status' => ['Read-only index health, last synchronization and knowledge counts.', []],
            'context_route' => ['Choose project memory, Laravel Boost search-docs, source inspection, or a combination. Does not fetch documentation.', ['task' => $text], ['task']],
            'sync_index' => ['Incremental synchronization. Requires configured write access and confirm=true.', ['confirm' => ['type' => 'boolean']], ['confirm']],
            'record_knowledge' => ['Record a draft or explicitly approved source-backed assertion. Revisions preserve previous versions.', [
                'confirm' => ['type' => 'boolean'], 'title' => $text + ['maxLength' => 255], 'body' => $text,
                'kind' => ['type' => 'string', 'enum' => ['architectural_rule', 'module_summary', 'convention', 'decision', 'pattern', 'lesson']],
                'approve' => ['type' => 'boolean'], 'symbols' => $refs, 'files' => $refs, 'previous_key' => $text,
            ], ['confirm', 'title', 'body']],
        ];
        $tools = [];
        foreach ($definitions as $name => [$description, $properties]) {
            $tools[] = [
                'name' => $name,
                'description' => $description,
                'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $definitions[$name][2] ?? [], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => ! in_array($name, ['sync_index', 'record_knowledge'], true), 'destructiveHint' => false, 'openWorldHint' => false],
            ];
        }

        return $tools;
    }

    public function call(array $params): array
    {
        try {
            $this->validateCall($params);
            $name = $params['name'];
            $arguments = $params['arguments'] ?? [];
            $payload = match ($name) {
                'project_overview', 'module_context', 'symbol_lookup', 'impact_analysis', 'change_history', 'architecture_rules' => $this->retriever->retrieve($name, $arguments)->toArray(),
                'context_route' => $this->router->route((string) $arguments['task']),
                'index_status' => $this->status(),
                'sync_index' => $this->sync($arguments),
                'record_knowledge' => $this->learn($arguments),
            };

            return [
                'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]],
                'structuredContent' => $payload,
                'isError' => false,
            ];
        } catch (\Throwable $exception) {
            return ['content' => [['type' => 'text', 'text' => $exception->getMessage()]], 'isError' => true];
        }
    }

    private function validateCall(array $params): void
    {
        if (! is_string($params['name'] ?? null) || ! is_array($params['arguments'] ?? [])) {
            throw new \InvalidArgumentException('tools/call requires a tool name and an arguments object.');
        }
        $definitions = array_column($this->tools(), null, 'name');
        $definition = $definitions[$params['name']] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Unknown tool ['.$params['name'].'].');
        }
        $arguments = $params['arguments'] ?? [];
        $schema = $definition['inputSchema'];
        foreach ($schema['required'] as $key) {
            if (! array_key_exists($key, $arguments)) {
                throw new \InvalidArgumentException('Missing required argument ['.$key.'].');
            }
        }
        $properties = (array) $schema['properties'];
        foreach ($arguments as $key => $value) {
            $property = $properties[$key] ?? null;
            if ($property === null) {
                throw new \InvalidArgumentException('Unknown argument ['.$key.'].');
            }
            $valid = match ($property['type']) {
                'string' => is_string($value) && trim($value) !== '' && (! isset($property['maxLength']) || mb_strlen($value) <= $property['maxLength']),
                'integer' => is_int($value) && $value >= ($property['minimum'] ?? PHP_INT_MIN) && $value <= ($property['maximum'] ?? PHP_INT_MAX),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value) && count($value) <= ($property['maxItems'] ?? 100)
                    && count(array_filter($value, fn ($item) => ! is_string($item) || trim($item) === '')) === 0,
            };
            if (! $valid || (isset($property['enum']) && ! in_array($value, $property['enum'], true))) {
                throw new \InvalidArgumentException('Invalid argument ['.$key.']. Expected '.$property['type'].'.');
            }
        }
    }

    private function sync(array $arguments): array
    {
        $this->guardWrite($arguments);
        $run = $this->synchronizer->sync('incremental', false);
        if ($run->status !== 'completed' || ($run->errors ?? []) !== []) {
            throw new \RuntimeException('Synchronization failed; previous index was retained. Inspect ai:status and live source.');
        }

        return ['status' => $run->status, 'files_updated' => $run->files_updated, 'files_skipped' => $run->files_skipped, 'duration_ms' => $run->duration_ms];
    }

    private function learn(array $arguments): array
    {
        $this->guardWrite($arguments);
        $this->schema->install();
        $parameters = [$arguments['title'], $arguments['body'], $arguments['kind'] ?? 'decision',
            ($arguments['approve'] ?? false) === true ? AiKnowledge::STATE_ACTIVE : AiKnowledge::STATE_DRAFT,
            $arguments['symbols'] ?? [], $arguments['files'] ?? [], 'mcp:explicit-confirmation'];
        $row = isset($arguments['previous_key'])
            ? $this->knowledge->revise($arguments['previous_key'], ...$parameters)
            : $this->knowledge->record(...$parameters);

        return $row->only(['knowledge_key', 'state', 'version', 'confidence']);
    }

    private function guardWrite(array $arguments): void
    {
        if (config('project-memory.mcp.allow_write', false) !== true || ($arguments['confirm'] ?? false) !== true) {
            throw new McpWriteForbiddenException('MCP writes require project-memory.mcp.allow_write=true and boolean confirm=true. Use ai:sync or ai:learn locally instead.');
        }
    }

    private function status(): array
    {
        if (! $this->schema->ready()) {
            return ['initialized' => false, 'healthy' => false, 'action' => 'Run ai:scan; inspect source if the memory database is unavailable.'];
        }
        $last = AiIndexRun::query()->latest('id')->first();
        $states = AiKnowledge::query()->selectRaw('state, COUNT(*) AS aggregate')->groupBy('state')->pluck('aggregate', 'state');

        return $this->health->inspect() + [
            'modules' => AiModule::query()->count(), 'nodes' => AiCodeNode::query()->count(), 'edges' => AiCodeEdge::query()->count(),
            'active_knowledge' => (int) $states->get(AiKnowledge::STATE_ACTIVE, 0), 'knowledge_states' => $states->all(),
            'last_status' => $last?->status, 'last_errors' => $last?->errors ?? [], 'last_metrics' => $last?->metadata ?? [],
            'source_authoritative' => true,
        ];
    }

    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
