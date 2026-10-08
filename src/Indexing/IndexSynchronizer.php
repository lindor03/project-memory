<?php

namespace ProjectMemory\Indexing;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use ProjectMemory\Data\ExtractedNode;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Exceptions\IndexLockedException;
use ProjectMemory\Knowledge\KnowledgeInvalidator;
use ProjectMemory\Knowledge\RuleCatalog;
use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiIndexRun;
use ProjectMemory\Models\AiModule;
use ProjectMemory\Schema\SchemaInstaller;
use ProjectMemory\Support\NodeKeys;

class IndexSynchronizer
{
    private array $endpointIds = [];

    private array $moduleCache = [];

    private ?Collection $tableCache = null;

    private array $tableContributors = [];

    public function __construct(
        private readonly SchemaInstaller $schema,
        private readonly SourceScanner $scanner,
        private readonly FileHasher $hasher,
        private readonly ExtractionPipeline $pipeline,
        private readonly ModuleDiscoverer $modules,
        private readonly KnowledgeInvalidator $invalidator,
        private readonly RuleCatalog $rules,
        private readonly AnalyzerFingerprint $fingerprint,
        private readonly ExtractionCache $cache,
        private readonly MethodDeclarationResolver $methodResolver,
        private readonly LaravelRelationshipResolver $laravelResolver,
    ) {}

    public function sync(string $scope = 'incremental', bool $force = false): AiIndexRun
    {
        $connection = AiCodeNode::query()->getConnection();
        $identity = $connection->getName().'|'.$connection->getDatabaseName();
        $lease = max(60, (int) config('project-memory.index.lock_seconds', 3600));
        $lock = Cache::lock('project-memory-index:'.hash('sha256', $identity), $lease);
        if (! $lock->get()) {
            throw new IndexLockedException('Another project-memory index run holds the lock.');
        }
        $databaseLock = null;
        $started = hrtime(true);
        $this->endpointIds = $this->moduleCache = [];
        $this->tableCache = null;
        $this->tableContributors = [];
        $queries = $queryMs = 0;
        $dispatcher = $connection->getEventDispatcher();
        if ($dispatcher !== null) {
            $observer = clone $dispatcher;
            $observer->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries, &$queryMs) {
                $queries++;
                $queryMs += $event->time;
            });
            $connection->setEventDispatcher($observer);
        }
        try {
            if ($connection->getDriverName() === 'mysql') {
                $name = 'pm:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 60);
                $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
                if ((int) $acquired->acquired !== 1) {
                    throw new IndexLockedException('Another synchronizer holds the database advisory lock.');
                }
                $databaseLock = $name;
            }
            $this->schema->install();
            $repositoryPath = NodeKeys::absolute((string) realpath(config('project-memory.scan.base_path', base_path())));
            $previousRun = AiIndexRun::query()->where('status', AiIndexRun::STATUS_COMPLETED)->latest('id')->first();
            $previousPath = $previousRun?->metadata['repository_path'] ?? null;
            if (is_string($previousPath) && $previousPath !== $repositoryPath) {
                throw new \RuntimeException('This memory database belongs to another repository ['.$previousPath.']. Configure a separate memory database.');
            }
            AiIndexRun::query()->where('status', AiIndexRun::STATUS_RUNNING)->update([
                'status' => AiIndexRun::STATUS_FAILED, 'finished_at' => now(),
                'errors' => [['message' => 'Superseded after obtaining the index lock.']],
            ]);
            $analyzer = $this->fingerprint->current();
            $run = AiIndexRun::query()->create(['scan_scope' => $scope, 'git_revision' => $this->gitRevision(), 'status' => AiIndexRun::STATUS_RUNNING, 'started_at' => now(), 'errors' => [], 'metadata' => ['analyzer_fingerprint' => $analyzer, 'repository_path' => $repositoryPath]]);
            $files = AiCodeNode::query()->where('node_type', 'file')->get()->keyBy('file_path');
            $discovered = $this->scanner->discover();
            $prepared = $errors = $seen = [];
            $declarations = [];
            $skipped = $cacheHits = 0;
            foreach ($discovered as $file) {
                $path = $file['path'];
                $seen[$path] = true;
                try {
                    // Hash exactly the bytes parsed, even if an editor changes
                    // the file between discovery and reading.
                    $contents = file_get_contents($file['absolute']);
                    if ($contents === false) {
                        throw new \RuntimeException('The file could not be read.');
                    }
                    $hash = $this->hasher->content($contents);
                    $previous = $files->get($path);
                    if (! $force && $previous !== null && $previous->content_hash === $hash && ($previous->metadata['analyzer_fingerprint'] ?? null) === $analyzer) {
                        $skipped++;
                        $this->assertLease($started, $lease);

                        continue;
                    }
                    $key = $this->cache->key($path, $hash, $analyzer);
                    $extraction = $force ? null : $this->cache->get($key);
                    if ($extraction === null) {
                        $extraction = $this->pipeline->extract($path, $contents, $hash, strlen($contents));
                        $this->cache->put($key, $analyzer, $extraction);
                    } else {
                        $cacheHits++;
                    }
                    foreach ($extraction->nodes as $node) {
                        if ($node->shared) {
                            continue;
                        }
                        $identity = preg_match('/^(class|method|function):/', $node->key) ? strtolower($node->key) : $node->key;
                        if (isset($declarations[$identity]) && $declarations[$identity]['path'] !== $path) {
                            throw new \RuntimeException('Duplicate declaration ['.$node->key.'] in '.$declarations[$identity]['path'].' and '.$path.'.');
                        }
                        $declarations[$identity] = ['key' => $node->key, 'path' => $path];
                    }
                    // Stage addresses instead of retaining the full AST-derived
                    // corpus in memory while all files are parsed.
                    $prepared[$path] = $key;
                } catch (\Throwable $exception) {
                    $errors[] = ['file' => $path, 'message' => $exception->getMessage()];
                }
                $this->assertLease($started, $lease);
            }
            // Changed files cannot silently steal an unchanged declaration.
            foreach (array_chunk(array_column($declarations, 'key'), 500) as $keys) {
                foreach (AiCodeNode::query()->whereIn('node_key', $keys)->whereNotNull('file_path')->get(['node_key', 'file_path']) as $node) {
                    $identity = preg_match('/^(class|method|function):/', $node->node_key) ? strtolower($node->node_key) : $node->node_key;
                    $nextPath = $declarations[$identity]['path'];
                    if ($node->file_path !== $nextPath && ! isset($prepared[$node->file_path]) && is_file($repositoryPath.'/'.$node->file_path)) {
                        $errors[] = ['file' => $nextPath, 'message' => 'Duplicate declaration ['.$node->node_key.'] is still owned by '.$node->file_path.'.'];
                    }
                }
            }
            $deleted = $changes = [];
            $resolution = ['resolved_edges' => 0, 'updated_edges' => 0, 'unresolved_edges' => 0];
            $laravelResolution = ['annotated_edges' => 0, 'facade_edges' => 0, 'fluent_edges' => 0, 'form_request_edges' => 0];
            $invalidated = $catalog = $orphans = 0;
            // Cache housekeeping cannot turn a successful publication into a
            // failed run after the graph has already committed.
            $this->cache->prune();
            $finish = function () use ($run, $discovered, $prepared, $skipped, $started, $errors, $repositoryPath, $force, $analyzer, $cacheHits, &$deleted, &$changes, &$invalidated, &$catalog, &$queries, &$queryMs, &$resolution, &$laravelResolution) {
                $run->forceFill([
                    'files_scanned' => count($discovered), 'files_updated' => $errors === [] ? count($prepared) : 0,
                    'files_skipped' => $skipped, 'deleted_nodes' => count($deleted),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'errors' => $errors,
                    'status' => $errors === [] ? AiIndexRun::STATUS_COMPLETED : AiIndexRun::STATUS_FAILED, 'finished_at' => now(),
                    'metadata' => ['repository_path' => $repositoryPath, 'warnings' => $this->scanner->warnings(), 'invalidated_knowledge' => $invalidated, 'catalog_rules' => $catalog,
                        'forced' => $force, 'analyzer_fingerprint' => $analyzer, 'extraction_cache_hits' => $cacheHits,
                        'method_resolution' => $resolution,
                        'laravel_resolution' => $laravelResolution,
                        'query_count' => $queries, 'query_time_ms' => round($queryMs, 3), 'publication' => $errors === [] ? 'atomic' : 'previous_graph_retained',
                        'changed_symbols' => array_keys($changes), 'removed_symbols' => $deleted],
                ])->save();
            };
            if ($errors === []) {
                $this->assertLease($started, $lease);
                $connection->transaction(function () use ($prepared, $files, $seen, $analyzer, &$deleted, &$changes, &$invalidated, &$catalog, &$orphans, &$resolution, &$laravelResolution, $started, $lease, $finish) {
                    $tableHashes = AiCodeNode::query()->where('node_type', 'table')->pluck('content_hash', 'node_key');
                    foreach ($prepared as $path => $key) {
                        $extraction = $this->cache->get($key);
                        if ($extraction === null) {
                            throw new \RuntimeException('A staged extraction became unavailable; graph publication was rolled back.');
                        }
                        $result = $this->persistFile($path, $extraction, $analyzer);
                        $changes = array_replace($changes, $result['changes']);
                        $deleted = array_merge($deleted, $result['deleted']);
                        $this->assertLease($started, $lease);
                    }
                    $deleted = array_merge($deleted, $this->deleteMissingFiles($files, $seen));
                    if ($prepared !== [] || $deleted !== []) {
                        $this->reconcileSharedTables();
                        $resolution = $this->methodResolver->resolve();
                        $laravelResolution = $this->laravelResolver->resolve();
                    }
                    $orphanKeys = $this->deleteOrphans();
                    $orphans = count($orphanKeys);
                    $deleted = array_merge($deleted, $orphanKeys);
                    foreach (AiCodeNode::query()->where('node_type', 'table')->pluck('content_hash', 'node_key') as $key => $hash) {
                        if ($tableHashes->get($key) !== $hash) {
                            $changes[$key] = $hash;
                        }
                    }
                    $invalidated = $this->invalidator->invalidate($changes, $deleted);
                    $catalog = $this->rules->sync();
                    $this->assertLease($started, $lease);
                    $finish();
                });
            } else {
                $finish();
            }

            return $run;
        } catch (\Throwable $exception) {
            if (isset($run)) {
                $run->forceFill(['status' => AiIndexRun::STATUS_FAILED, 'finished_at' => now(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
                    'errors' => [['message' => $exception->getMessage()]], 'metadata' => array_merge($run->metadata ?? [], ['publication' => 'previous_graph_retained'])])->save();
            }
            throw $exception;
        } finally {
            try {
                if ($databaseLock !== null) {
                    try {
                        $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$databaseLock]);
                    } finally {
                        $lock->release();
                    }
                } else {
                    $lock->release();
                }
            } finally {
                if ($dispatcher !== null) {
                    $connection->setEventDispatcher($dispatcher);
                }
            }
        }
    }

    private function assertLease(int $started, int $lease): void
    {
        if ((hrtime(true) - $started) / 1e9 > $lease - 10) {
            throw new \RuntimeException('Index lock lease nearly exhausted; graph publication rolled back. Increase index.lock_seconds.');
        }
    }

    private function persistFile(string $path, FileExtraction $extraction, string $analyzer): array
    {
        $assigned = $this->modules->assign($path);
        $module = $this->moduleCache[$assigned['key']] ??= $this->modules->ensure($path);
        $pathHash = NodeKeys::pathHash($path);
        $old = AiCodeNode::query()->where('path_hash', $pathHash)->get()->keyBy('node_key');
        AiCodeEdge::query()->where('declaration_path_hash', $pathHash)->delete();
        AiCodeEdge::query()->whereNull('declaration_path_hash')->whereIn('source_node_id', $old->pluck('id'))->delete();
        // Clear old schema contributions before adding their replacements.
        $this->forgetSharedColumns($path);
        $owned = [new ExtractedNode(NodeKeys::file($path), 'file', $path, 1, null, $extraction->contentHash, 'file '.$path,
            ['bytes' => $extraction->bytes, 'analyzer_fingerprint' => $analyzer, 'notes' => $extraction->notes])];
        foreach ($extraction->nodes as $node) {
            if ($node->shared) {
                $this->upsertShared($node, $module);
            } else {
                $owned[] = $node;
            }
        }
        $rows = $kept = $changes = [];
        foreach ($owned as $node) {
            $kept[$node->key] = true;
            if (($old->get($node->key)?->content_hash) !== $node->contentHash) {
                $changes[$node->key] = $node->contentHash;
            }
            $rows[] = ['node_key' => $node->key, 'module_id' => $module->id, 'node_type' => $node->type, 'symbol_name' => $this->limit($node->symbol),
                'file_path' => $path, 'path_hash' => $pathHash, 'content_hash' => $node->contentHash, 'start_line' => $node->startLine, 'end_line' => $node->endLine,
                'summary' => $node->summary, 'metadata' => json_encode(array_merge($node->metadata, ['shared' => false, 'stub' => false]), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            AiCodeNode::query()->upsert($batch, ['node_key'], ['module_id', 'node_type', 'symbol_name', 'file_path', 'path_hash', 'content_hash', 'start_line', 'end_line', 'summary', 'metadata', 'updated_at']);
        }
        $stale = $old->reject(fn ($node) => isset($kept[$node->node_key]) || ! empty($node->metadata['shared']));
        $deleted = $stale->pluck('node_key')->all();
        $this->retireNodes($stale);
        $keys = array_keys($kept);
        $endpoints = [];
        foreach ($extraction->edges as $edge) {
            $keys[] = $edge->sourceKey;
            $keys[] = $edge->targetKey;
            $endpoints[$edge->sourceKey] ??= ['class', substr($edge->sourceKey, strpos($edge->sourceKey, ':') + 1)];
            $endpoints[$edge->targetKey] = [$edge->targetType, $edge->targetSymbol];
        }
        $unknown = array_values(array_diff(array_unique($keys), array_keys($this->endpointIds)));
        foreach (array_chunk($unknown, 500) as $batch) {
            foreach (AiCodeNode::query()->whereIn('node_key', $batch)->pluck('id', 'node_key') as $key => $id) {
                $this->endpointIds[$key] = $id;
            }
        }
        $stubs = [];
        foreach ($endpoints as $key => [$type, $symbol]) {
            if (! isset($this->endpointIds[$key])) {
                $stubs[] = ['node_key' => $key, 'node_type' => $type, 'symbol_name' => $this->limit($symbol), 'content_hash' => null,
                    'summary' => null, 'metadata' => json_encode(['stub' => true]), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($stubs !== []) {
            foreach (array_chunk($stubs, 100) as $batch) {
                AiCodeNode::query()->insertOrIgnore($batch);
            }
            foreach (array_chunk(array_column($stubs, 'node_key'), 500) as $batch) {
                foreach (AiCodeNode::query()->whereIn('node_key', $batch)->pluck('id', 'node_key') as $key => $id) {
                    $this->endpointIds[$key] = $id;
                }
            }
        }
        $edgeRows = [];
        foreach ($extraction->edges as $edge) {
            $edgeRows[] = ['source_node_id' => $this->endpointIds[$edge->sourceKey], 'target_node_id' => $this->endpointIds[$edge->targetKey],
                'relationship' => $edge->relationship, 'confidence' => $edge->confidence, 'metadata' => json_encode($edge->metadata, JSON_THROW_ON_ERROR),
                'declaration_path_hash' => $pathHash, 'reference_hash' => hash('sha256', $edge->targetKey), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($edgeRows, 100) as $batch) {
            AiCodeEdge::query()->upsert($batch, ['source_node_id', 'target_node_id', 'relationship', 'declaration_path_hash', 'reference_hash'], ['confidence', 'metadata', 'updated_at']);
        }

        return ['changes' => $changes, 'deleted' => $deleted];
    }

    private function upsertShared(ExtractedNode $node, AiModule $module): void
    {
        $tables = $this->tables();
        $model = $tables->get($node->key) ?? new AiCodeNode(['node_key' => $node->key]);
        $metadata = $model->metadata ?? [];
        $columns = array_merge($metadata['columns'] ?? [], $node->metadata['columns'] ?? []);
        $metadata = array_merge($metadata, $node->metadata, ['columns' => $columns, 'shared' => true, 'stub' => false]);
        $model->fill(['module_id' => $model->module_id ?? $module->id, 'node_type' => $node->type, 'symbol_name' => $this->limit($node->symbol),
            'content_hash' => hash('sha256', json_encode($columns)), 'summary' => $node->summary, 'metadata' => $metadata])->save();
        $this->endpointIds[$node->key] = $model->id;
        $tables->put($node->key, $model);
        if (is_string($node->metadata['source'] ?? null)) {
            $this->tableContributors[$node->metadata['source']][$node->key] = true;
        }
    }

    private function deleteMissingFiles($files, array $seen): array
    {
        $deleted = [];
        $base = NodeKeys::absolute((string) config('project-memory.scan.base_path', base_path()));
        $roots = array_map(fn ($root) => trim(str_replace('\\', '/', (string) $root), '/'), config('project-memory.scan.roots', []));
        foreach ($files as $file) {
            $path = (string) $file->file_path;
            if (isset($seen[$path]) || is_file($base.'/'.$path) || ! $this->underRoots($path, $roots)) {
                continue;
            }
            $nodes = AiCodeNode::query()->where('path_hash', $file->path_hash)->get();
            AiCodeEdge::query()->where('declaration_path_hash', $file->path_hash)->delete();
            AiCodeEdge::query()->whereNull('declaration_path_hash')->whereIn('source_node_id', $nodes->pluck('id'))->delete();
            $deleted = array_merge($deleted, $nodes->pluck('node_key')->all());
            $this->retireNodes($nodes);
            $this->forgetSharedColumns($path);
        }

        return $deleted;
    }

    private function retireNodes($nodes): void
    {
        if ($nodes->isEmpty()) {
            return;
        }
        $ids = $nodes->pluck('id')->all();
        $edges = AiCodeEdge::query()->whereIn('target_node_id', $ids)->orWhereIn('source_node_id', $ids)->get(['source_node_id', 'target_node_id']);
        $referenced = $edges->pluck('target_node_id')->merge($edges->pluck('source_node_id'))->flip();
        foreach ($nodes as $node) {
            if ($referenced->has($node->id) && $node->node_type !== 'file') {
                $node->forceFill(['file_path' => null, 'path_hash' => null, 'content_hash' => null, 'module_id' => null, 'start_line' => null, 'end_line' => null,
                    'summary' => null, 'metadata' => ['stub' => true, 'removed_from' => $node->file_path]])->save();
            } else {
                $node->delete();
                unset($this->endpointIds[$node->node_key]);
            }
        }
    }

    private function forgetSharedColumns(string $path): void
    {
        if (! str_contains($path, 'migration')) {
            return;
        }
        $this->tables();
        foreach (array_keys($this->tableContributors[$path] ?? []) as $key) {
            $table = $this->tableCache->get($key);
            $metadata = $table->metadata ?? [];
            $old = $metadata['columns'] ?? [];
            $columns = array_values(array_filter($old, fn (array $column) => ($column['source'] ?? null) !== $path));
            if ($old !== $columns) {
                $metadata['columns'] = $columns;
                $table->forceFill(['metadata' => $metadata, 'content_hash' => hash('sha256', json_encode($columns))])->save();
            }
        }
        unset($this->tableContributors[$path]);
    }

    private function tables(): Collection
    {
        if ($this->tableCache === null) {
            $this->tableCache = AiCodeNode::query()->where('node_type', 'table')->get()->keyBy('node_key');
            foreach ($this->tableCache as $key => $table) {
                $sources = array_column($table->metadata['columns'] ?? [], 'source');
                $sources[] = $table->metadata['source'] ?? null;
                foreach (array_filter($sources, 'is_string') as $source) {
                    $this->tableContributors[$source][$key] = true;
                }
            }
        }

        return $this->tableCache;
    }

    private function reconcileSharedTables(): void
    {
        $tables = $this->tables();
        if ($tables->isEmpty()) {
            return;
        }
        $sources = [];
        $edges = AiCodeEdge::query()->where('relationship', 'defines_table')->with('source')->get();
        foreach ($edges as $edge) {
            if (is_string($edge->source?->file_path)) {
                $sources[$edge->target_node_id][$edge->source->file_path] = true;
            }
        }
        foreach ($tables as $table) {
            $metadata = $table->metadata ?? [];
            $paths = array_keys($sources[$table->id] ?? []);
            sort($paths);
            $columns = $metadata['columns'] ?? [];
            usort($columns, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $metadata['columns'] = $columns;
            $metadata['sources'] = $paths;
            $metadata['source'] = $paths[0] ?? null;
            $hash = hash('sha256', json_encode(['columns' => $columns, 'sources' => $paths]));
            if ($table->content_hash !== $hash || $table->metadata !== $metadata) {
                $table->forceFill(['metadata' => $metadata, 'content_hash' => $hash])->save();
            }
        }
    }

    private function deleteOrphans(): array
    {
        $query = AiCodeNode::query()->whereNull('file_path')->whereDoesntHave('incomingEdges')->whereDoesntHave('outgoingEdges');
        $keys = $query->pluck('node_key')->all();
        $query->delete();

        return $keys;
    }

    private function underRoots(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($root !== '' && ($path === $root || str_starts_with($path, $root.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function limit(?string $value): ?string
    {
        return $value === null ? null : mb_strcut($value, 0, 250, 'UTF-8');
    }

    private function gitRevision(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $contents = trim((string) file_get_contents($head));
        if (str_starts_with($contents, 'ref: ')) {
            $ref = base_path('.git/'.trim(substr($contents, 5)));

            return is_file($ref) ? trim((string) file_get_contents($ref)) : null;
        }

        return $contents ?: null;
    }
}
