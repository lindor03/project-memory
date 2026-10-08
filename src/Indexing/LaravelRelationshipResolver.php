<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Support\NodeKeys;

/**
 * Annotates indexed calls with facade, fluent and form-request evidence.
 * It does not retarget a call onto a guessed implementation.
 */
class LaravelRelationshipResolver
{
    public function __construct(private readonly FrameworkFacadeCatalog $facades) {}

    /** @return array{annotated_edges: int, facade_edges: int, fluent_edges: int, form_request_edges: int} */
    public function resolve(): array
    {
        $classes = [];
        foreach (AiCodeNode::query()->where('node_key', 'like', 'class:%')->get(['node_key', 'metadata', 'file_path']) as $node) {
            $extends = (string) ($node->metadata['extends'] ?? '');
            $classes[strtolower($node->node_key)] = [
                'eloquent' => (bool) ($node->metadata['eloquent_model'] ?? false),
                'form_request' => (bool) ($node->metadata['form_request'] ?? false) || str_ends_with($extends, 'FormRequest'),
                'stub' => (bool) ($node->metadata['stub'] ?? false),
                'accessor' => $node->metadata['facade_accessor'] ?? null,
                'accessor_kind' => $node->metadata['facade_accessor_kind'] ?? null,
            ];
        }
        $catalog = [];
        foreach ($this->facades->accessors() as $class => $accessor) {
            $catalog[strtolower(NodeKeys::normalize('class:'.$class))] = $accessor;
        }
        $bindings = [];
        foreach (AiCodeEdge::query()->where('relationship', 'binds')->with('target:id,symbol_name')->get(['metadata', 'target_node_id']) as $edge) {
            $abstract = $edge->metadata['abstract'] ?? null;
            $concrete = $edge->target->symbol_name ?? null;
            if (is_string($abstract) && is_string($concrete) && $concrete !== '') {
                $bindings[$abstract][$concrete] = true;
            }
        }
        $counts = ['annotated_edges' => 0, 'facade_edges' => 0, 'fluent_edges' => 0, 'form_request_edges' => 0];
        $formRequests = array_keys(array_filter($classes, fn (array $class) => $class['form_request']));

        AiCodeEdge::query()->where('relationship', 'calls')->with('target:id,symbol_name,metadata')->chunkById(200, function ($edges) use ($classes, $catalog, $bindings, &$counts) {
            foreach ($edges as $edge) {
                if ($edge->target === null || ! is_string($edge->target->symbol_name) || ! str_contains($edge->target->symbol_name, '::')) {
                    continue;
                }
                [$class, $method] = explode('::', $edge->target->symbol_name, 2);
                $classKey = strtolower(NodeKeys::normalize('class:'.$class));
                $metadata = $edge->metadata ?? [];
                if (in_array($metadata['resolution'] ?? null, ['declared_method', 'inherited_method'], true)) {
                    continue;
                }
                $facade = $catalog[$classKey] ?? null;
                $appFacade = $classes[$classKey]['accessor'] ?? null;
                if (is_string($appFacade) && $appFacade !== '') {
                    $facade = ['accessor' => $appFacade, 'kind' => $classes[$classKey]['accessor_kind'] ?? 'container_key'];
                }
                $next = null;
                if (is_array($facade)) {
                    $concretes = array_keys($bindings[$facade['accessor']] ?? []);
                    $state = count($concretes) > 1 ? 'ambiguous' : 'partially_resolved';
                    if ($facade['kind'] === 'class' && ! isset($classes[strtolower(NodeKeys::normalize('class:'.$facade['accessor']))])) {
                        $state = count($concretes) === 1 ? 'partially_resolved' : 'external';
                    }
                    $next = $metadata + [
                        'resolution' => 'facade_accessor',
                        'resolution_state' => $state,
                        'facade_accessor' => $facade['accessor'],
                        'facade_accessor_kind' => $facade['kind'],
                        'bound_concrete' => count($concretes) === 1 ? $concretes[0] : null,
                    ];
                    $counts['facade_edges']++;
                } elseif (($classes[$classKey]['eloquent'] ?? false) && LaravelFluentMethods::isFluent($method)) {
                    $next = $metadata + [
                        'resolution' => 'eloquent_builder_forward',
                        'resolution_state' => 'partially_resolved',
                        'forward_receiver' => $class,
                    ];
                    $counts['fluent_edges']++;
                }
                if ($next === null || $next == $metadata) {
                    continue;
                }
                $edge->forceFill(['metadata' => $next])->save();
                $counts['annotated_edges']++;
            }
        });

        if ($formRequests !== []) {
            AiCodeEdge::query()->where('relationship', 'depends_on')->with('target:id,node_key')->chunkById(200, function ($edges) use ($formRequests, &$counts) {
                foreach ($edges as $edge) {
                    $key = strtolower((string) $edge->target?->node_key);
                    if (! in_array($key, $formRequests, true)) {
                        continue;
                    }
                    $metadata = $edge->metadata ?? [];
                    if (($metadata['via'] ?? null) !== 'parameter' && ($metadata['via'] ?? null) !== 'form_request') {
                        continue;
                    }
                    $metadata['via'] = 'form_request';
                    $metadata['resolution'] = 'form_request';
                    $metadata['resolution_state'] = 'resolved';
                    if ($metadata == $edge->metadata) {
                        continue;
                    }
                    $edge->forceFill(['metadata' => $metadata])->save();
                    $counts['form_request_edges']++;
                    $counts['annotated_edges']++;
                }
            });
        }

        return $counts;
    }
}
