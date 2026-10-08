<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Support\NodeKeys;

/** Resolve inherited declarations after all files are indexed, without executing project code. */
class MethodDeclarationResolver
{
    /** @var array<string, array{key: string, opaque: bool}> */
    private array $classes = [];

    /** @var array<string, array{id: int, key: string, private: bool}> */
    private array $methods = [];

    /** @var array<string, array<string, list<string>>> */
    private array $ancestry = [];

    /** @var array<string, array{id: int, key: string, path: list<string>}|null> */
    private array $cache = [];

    /** @return array{resolved_edges: int, updated_edges: int, unresolved_edges: int} */
    public function resolve(): array
    {
        $this->classes = $this->methods = $this->ancestry = $this->cache = [];
        foreach (AiCodeNode::query()->where('node_key', 'like', 'class:%')->get(['node_key', 'metadata']) as $class) {
            if (! ($class->metadata['stub'] ?? false)) {
                $this->classes[strtolower($class->node_key)] = ['key' => $class->node_key, 'opaque' => (bool) ($class->metadata['trait_adaptations'] ?? false)];
            }
        }
        $stubs = [];
        $endpoints = [];
        foreach (AiCodeNode::query()->where('node_type', 'method')->get(['id', 'node_key', 'metadata']) as $method) {
            $endpoints[$method->node_key] = $method->id;
            if ($method->metadata['stub'] ?? false) {
                $stubs[] = $method->id;
            } else {
                $this->methods[strtolower($method->node_key)] = ['id' => $method->id, 'key' => $method->node_key, 'private' => ($method->metadata['visibility'] ?? null) === 'private'];
            }
        }
        foreach (AiCodeEdge::query()->whereIn('relationship', ['extends', 'uses_trait'])->with(['source:id,node_key', 'target:id,node_key'])->get() as $edge) {
            if ($edge->source !== null && $edge->target !== null) {
                $this->ancestry[strtolower($edge->source->node_key)][$edge->relationship][] = strtolower($edge->target->node_key);
            }
        }
        $counts = ['resolved_edges' => 0, 'updated_edges' => 0, 'unresolved_edges' => 0];
        $highestEdge = AiCodeEdge::query()->max('id') ?? 0;

        AiCodeEdge::query()->getConnection()->transaction(function () use ($stubs, $highestEdge, &$endpoints, &$counts): void {
            AiCodeEdge::query()->where('id', '<=', $highestEdge)->where('relationship', 'calls')->where(function ($query) use ($stubs) {
                $query->whereIn('target_node_id', $stubs)->orWhereNotNull('metadata->original_target_key');
            })->with('target:id,node_key,symbol_name')->chunkById(200, function ($edges) use (&$endpoints, &$counts): void {
                $rows = [];
                $delete = [];
                foreach ($edges as $edge) {
                    if ($edge->target === null) {
                        continue;
                    }
                    $metadata = $edge->metadata ?? [];
                    $original = $metadata['original_target_key'] ?? $edge->target->node_key;
                    $resolved = $this->resolveMethod($original);
                    $counts[$resolved === null ? 'unresolved_edges' : 'resolved_edges']++;
                    // Leave a never-resolved call untouched. Its stub is useful
                    // evidence that static analysis could not find a declaration.
                    if ($resolved === null && ! isset($metadata['original_target_key'])) {
                        continue;
                    }
                    $originalConfidence = (float) ($metadata['original_confidence'] ?? $edge->confidence);
                    $symbol = $metadata['original_target_symbol'] ?? $edge->target->symbol_name;
                    $metadata['original_target_key'] = $original;
                    $metadata['original_target_symbol'] = $symbol;
                    $metadata['original_confidence'] = $originalConfidence;
                    $metadata['resolution'] = $resolved === null ? 'unresolved_method' : (strcasecmp($resolved['key'], $original) === 0 ? 'declared_method' : 'inherited_method');
                    $metadata['resolution_path'] = $resolved['path'] ?? [];
                    $confidence = $resolved !== null && $metadata['resolution'] === 'inherited_method' ? min(0.85, $originalConfidence) : $originalConfidence;
                    if ($resolved === null) {
                        if (! isset($endpoints[$original])) {
                            $stub = AiCodeNode::query()->firstOrCreate(['node_key' => $original], ['node_type' => 'method', 'symbol_name' => $symbol, 'content_hash' => null, 'summary' => null, 'metadata' => ['stub' => true]]);
                            $endpoints[$original] = $stub->id;
                        }
                        $targetId = $endpoints[$original];
                    } else {
                        $targetId = $resolved['id'];
                    }
                    if ($targetId === $edge->target_node_id && $metadata === $edge->metadata && abs($confidence - (float) $edge->confidence) < 0.001) {
                        continue;
                    }
                    $counts['updated_edges']++;
                    $rows[] = ['source_node_id' => $edge->source_node_id, 'target_node_id' => $targetId, 'relationship' => $edge->relationship, 'declaration_path_hash' => $edge->declaration_path_hash, 'reference_hash' => $edge->reference_hash, 'confidence' => $confidence, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => $edge->created_at, 'updated_at' => now()];
                    $delete[] = $edge->id;
                }
                if ($rows !== []) {
                    // Delete first so an unchanged endpoint can be reinserted
                    // safely even on databases with nullable unique columns.
                    AiCodeEdge::query()->whereIn('id', $delete)->delete();
                    AiCodeEdge::query()->upsert($rows, ['source_node_id', 'target_node_id', 'relationship', 'declaration_path_hash', 'reference_hash'], ['confidence', 'metadata', 'updated_at']);
                }
            });
        });

        return $counts;
    }

    /** @return array{id: int, key: string, path: list<string>}|null */
    private function resolveMethod(string $key): ?array
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $separator = strrpos($key, '::');
        if (! str_starts_with($key, 'method:') || $separator === false) {
            return $this->cache[$key] = null;
        }
        $class = strtolower(NodeKeys::normalize('class:'.substr($key, 7, $separator - 7)));
        $method = substr($key, $separator + 2);
        $candidates = $this->declarations($class, $method, []);

        return $this->cache[$key] = $candidates !== null && count($candidates) === 1 ? array_values($candidates)[0] : null;
    }

    /**
     * null means opaque or ambiguous ancestry; [] means no known declaration.
     *
     * @param  list<string>  $visited
     * @return array<int, array{id: int, key: string, path: list<string>}>|null
     */
    private function declarations(string $class, string $method, array $visited): ?array
    {
        if (in_array($class, $visited, true) || count($visited) >= 32 || ! isset($this->classes[$class])) {
            return null;
        }
        $declaration = $this->classes[$class];
        $key = strtolower(NodeKeys::normalize('method:'.substr($declaration['key'], 6).'::'.$method));
        if (isset($this->methods[$key])) {
            $candidate = $this->methods[$key];
            if ($candidate['private'] && $visited !== []) {
                return null;
            }

            return [$candidate['id'] => ['id' => $candidate['id'], 'key' => $candidate['key'], 'path' => [$declaration['key'], $candidate['key']]]];
        }
        if ($declaration['opaque']) {
            return null;
        }
        $visited[] = $class;
        foreach (['uses_trait', 'extends'] as $relationship) {
            $candidates = [];
            foreach (array_unique($this->ancestry[$class][$relationship] ?? []) as $ancestor) {
                $found = $this->declarations($ancestor, $method, $visited);
                if ($found === null) {
                    return null;
                }
                foreach ($found as $id => $candidate) {
                    $candidate['path'] = [$declaration['key'], ...$candidate['path']];
                    $candidates[$id] = $candidate;
                }
            }
            if ($candidates !== []) {
                return count($candidates) === 1 ? $candidates : null;
            }
        }

        return [];
    }
}
