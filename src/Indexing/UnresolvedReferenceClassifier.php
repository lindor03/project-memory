<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Support\NodeKeys;

/**
 * Classifies unresolved call references. Counts are evidence, not an accuracy rate.
 */
class UnresolvedReferenceClassifier
{
    public function __construct(private readonly FrameworkFacadeCatalog $facades) {}

    public function report(): array
    {
        $classes = [];
        $methods = [];
        foreach (AiCodeNode::query()->whereIn('node_type', ['class', 'interface', 'trait', 'enum', 'method', 'event', 'job', 'listener', 'policy'])->get(['node_key', 'node_type', 'symbol_name', 'metadata', 'file_path']) as $node) {
            if ($node->node_type === 'method') {
                if (! ($node->metadata['stub'] ?? false)) {
                    $methods[strtolower($node->node_key)] = true;
                }

                continue;
            }
            $classes[strtolower($node->node_key)] = [
                'stub' => (bool) ($node->metadata['stub'] ?? false),
                'eloquent' => (bool) ($node->metadata['eloquent_model'] ?? false),
                'file' => $node->file_path,
            ];
        }
        $facades = [];
        foreach ($this->facades->accessors() as $class => $accessor) {
            $facades[strtolower(NodeKeys::normalize('class:'.$class))] = true;
        }
        $categories = [];
        $states = [];
        $examples = [];
        $edges = 0;
        AiCodeEdge::query()->where('relationship', 'calls')->with('target:id,node_key,symbol_name,metadata,file_path')->chunkById(300, function ($rows) use (&$categories, &$states, &$examples, &$edges, $classes, $methods, $facades) {
            foreach ($rows as $edge) {
                $edges++;
                $classified = $this->classifyEdge($edge, $classes, $methods, $facades);
                $categories[$classified['category']] = ($categories[$classified['category']] ?? 0) + 1;
                $states[$classified['state']] = ($states[$classified['state']] ?? 0) + 1;
                if (count($examples[$classified['category']] ?? []) < 3 && $classified['category'] !== 'resolved') {
                    $examples[$classified['category']][] = $classified['example'];
                }
            }
        });
        $dynamic = 0;
        $dynamicExamples = [];
        foreach (AiCodeNode::query()->where('node_type', 'method')->get(['symbol_name', 'metadata']) as $method) {
            $count = (int) ($method->metadata['unresolved_calls'] ?? 0);
            if ($count < 1) {
                continue;
            }
            $dynamic += $count;
            if (count($dynamicExamples) < 3) {
                $dynamicExamples[] = $method->symbol_name.' ('.$count.')';
            }
        }
        ksort($categories);
        ksort($states);

        return [
            'call_edges' => $edges,
            'states' => $states,
            'categories' => $categories,
            'examples' => $examples,
            'dynamic_untyped_call_sites' => $dynamic,
            'dynamic_dispatch_call_sites' => $dynamic,
            'dynamic_examples' => $dynamicExamples,
            'note' => 'Category counts describe static-analysis evidence. They are not an accuracy percentage. php_namespace_fallback and php_global_fallback are PHP function-resolution edges. missing_receiver_type means the receiver type was not proved. dynamic_dispatch_call_sites are variable or untyped calls stored on methods, not as edges. laravel_facade, external_vendor, fluent_chain, and analyzer_limitation are separate.',
        ];
    }

    /** @param array<string, array{stub: bool, eloquent: bool, file: ?string}> $classes @param array<string, bool> $methods @param array<string, bool> $facades @return array{category: string, state: string, example: string} */
    private function classifyEdge(AiCodeEdge $edge, array $classes, array $methods, array $facades): array
    {
        $symbol = (string) ($edge->target->symbol_name ?? $edge->target->node_key ?? '');
        $metadata = $edge->metadata ?? [];
        $class = str_contains($symbol, '::') ? explode('::', $symbol, 2)[0] : $symbol;
        $method = str_contains($symbol, '::') ? explode('::', $symbol, 2)[1] : '';
        $classKey = strtolower(NodeKeys::normalize('class:'.$class));
        $classInfo = $classes[$classKey] ?? null;
        $facts = [
            'symbol' => $symbol,
            'target_stub' => (bool) ($edge->target->metadata['stub'] ?? $edge->target->file_path === null),
            'application' => $this->application($class),
            'external_prefix' => $this->externalPrefix($class),
            'class_indexed' => $classInfo !== null && ! $classInfo['stub'],
            'class_stub' => $classInfo === null || $classInfo['stub'],
            'method_indexed' => isset($methods[strtolower((string) ($edge->target->node_key ?? ''))]),
            'resolution' => $metadata['resolution'] ?? null,
            'resolution_state' => $metadata['resolution_state'] ?? null,
            'eloquent' => (bool) ($classInfo['eloquent'] ?? false),
            'fluent' => $method !== '' && LaravelFluentMethods::isFluent($method),
            'facade' => isset($facades[$classKey]) || ($metadata['resolution'] ?? null) === 'facade_accessor',
            'via' => $metadata['via'] ?? null,
        ];
        $classified = $this->classify($facts);

        return $classified + ['example' => $symbol.' ['.$classified['state'].']'];
    }

    /**
     * @param  array{symbol?: string, target_stub: bool, application: bool, external_prefix: bool, class_indexed: bool, class_stub: bool, method_indexed: bool, resolution: ?string, resolution_state: ?string, eloquent: bool, fluent: bool, facade: bool, via: ?string}  $facts
     * @return array{category: string, state: string}
     */
    public function classify(array $facts): array
    {
        $resolution = $facts['resolution'];
        $state = $facts['resolution_state'];
        if ($facts['facade'] || $resolution === 'facade_accessor') {
            return ['category' => 'laravel_facade', 'state' => $this->state($state, 'partially_resolved')];
        }
        if ($resolution === 'container_binding' || in_array($facts['via'], ['app', 'resolve'], true)) {
            return ['category' => 'service_container', 'state' => $this->state($state, 'partially_resolved')];
        }
        if ($resolution === 'eloquent_builder_forward' || ($facts['eloquent'] && $facts['fluent'] && $facts['target_stub'])) {
            return ['category' => 'fluent_chain', 'state' => $this->state($state, 'partially_resolved')];
        }
        if ($resolution === 'unresolved_method') {
            return ['category' => 'inheritance_or_trait', 'state' => $this->state($state, 'unresolved')];
        }
        if ($resolution === 'namespace_fallback') {
            return ['category' => 'php_namespace_fallback', 'state' => 'partially_resolved'];
        }
        if ($resolution === 'global_fallback') {
            return ['category' => 'php_global_fallback', 'state' => 'partially_resolved'];
        }
        if ($resolution === 'framework_type' || ($facts['external_prefix'] && $facts['target_stub'])) {
            return ['category' => 'external_vendor', 'state' => 'external'];
        }
        if (! $facts['target_stub'] && $facts['method_indexed']) {
            return ['category' => 'resolved', 'state' => 'resolved'];
        }
        if (! $facts['target_stub']) {
            return ['category' => 'resolved', 'state' => $resolution === 'namespace_fallback' ? 'partially_resolved' : 'resolved'];
        }
        if ($facts['application'] && $facts['class_stub']) {
            return ['category' => 'missing_scan', 'state' => 'unresolved'];
        }
        if ($facts['application'] && $facts['method_indexed']) {
            return ['category' => 'analyzer_limitation', 'state' => 'unresolved'];
        }
        if ($facts['application']) {
            return ['category' => 'missing_receiver_type', 'state' => 'unresolved'];
        }
        if ($facts['external_prefix'] || ! $facts['application']) {
            return ['category' => 'external_vendor', 'state' => 'external'];
        }

        return ['category' => 'unresolved', 'state' => 'unresolved'];
    }

    private function state(?string $explicit, string $fallback): string
    {
        return in_array($explicit, ['resolved', 'partially_resolved', 'ambiguous', 'external', 'unresolved'], true)
            ? $explicit
            : $fallback;
    }

    private function application(string $class): bool
    {
        return preg_match('/^(App|Tests|Database)\\\\/', $class) === 1;
    }

    private function externalPrefix(string $class): bool
    {
        return preg_match('/^(Illuminate|Symfony|Carbon|Spatie|Laravel|PHPUnit|Pest|Mockery|Yajra|Brick|Doctrine|GuzzleHttp|Psr|PhpParser|Monolog|League|Ramsey|Faker|Hamcrest|NunoMaduro|Livewire|Filament|Dotenv|Fruitcake|Nette|TijsVerkoyen|PhpOption)\\\\/', $class) === 1;
    }
}
