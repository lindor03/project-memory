<?php

namespace ProjectMemory\Indexing;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\NodeKeys;

/**
 * Adds Laravel relationships that have direct source evidence.
 * Opaque closures, middleware aliases and dynamic facades stay unlinked.
 */
class LaravelSemanticAnalyzer
{
    /** @var list<array{key: string, fqcn: string, method: ?string, methodKey: ?string, eloquent: bool, provider: bool}> */
    private array $stack = [];

    public function analyze(FileExtraction $extraction, array $statements): void
    {
        $this->stack = [];
        $visitor = new class($this, $extraction) extends NodeVisitorAbstract
        {
            public function __construct(
                private readonly LaravelSemanticAnalyzer $analyzer,
                private readonly FileExtraction $extraction,
            ) {}

            public function enterNode(Node $node)
            {
                $this->analyzer->enter($node, $this->extraction);

                return null;
            }

            public function leaveNode(Node $node)
            {
                $this->analyzer->leave($node);

                return null;
            }
        };
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($statements);
    }

    public function enter(Node $node, FileExtraction $extraction): void
    {
        if ($node instanceof ClassLike && isset($node->namespacedName)) {
            $fqcn = $node->namespacedName->toString();
            $key = NodeKeys::normalize('class:'.$fqcn);
            $existing = $extraction->nodes[$key] ?? null;
            $eloquent = (bool) ($existing->metadata['eloquent_model'] ?? false);
            $parent = $existing->metadata['extends'] ?? null;
            $provider = is_string($parent) && str_ends_with($parent, '\\ServiceProvider');
            if (is_string($parent) && str_ends_with($parent, '\\FormRequest')) {
                if ($existing !== null) {
                    $existing->metadata['form_request'] = true;
                }
            }
            if ($node instanceof Class_ && $this->isFacadeParent($parent)) {
                $accessor = $this->facadeAccessor($node);
                if ($existing !== null && $accessor !== null) {
                    $existing->metadata['facade_accessor'] = $accessor['accessor'];
                    $existing->metadata['facade_accessor_kind'] = $accessor['kind'];
                    if ($accessor['kind'] === 'class') {
                        $this->edge($extraction, $key, 'class:'.$accessor['accessor'], 'class', $accessor['accessor'], 'depends_on', 0.7, [
                            'via' => 'facade_accessor',
                            'resolution' => 'facade_accessor',
                            'resolution_state' => 'partially_resolved',
                            'evidence_line' => $node->getStartLine(),
                        ]);
                    }
                }
            }
            $this->readObservedBy($node, $extraction, $key, $fqcn);
            $this->readPolicies($node, $extraction, $key);
            if ($eloquent && $existing !== null) {
                $this->readEloquentMetadata($node, $existing);
            }
            $this->stack[] = [
                'key' => $key,
                'fqcn' => $fqcn,
                'method' => null,
                'methodKey' => null,
                'eloquent' => $eloquent,
                'provider' => $provider,
            ];

            return;
        }

        if ($node instanceof ClassMethod && $this->stack !== []) {
            $current = &$this->stack[array_key_last($this->stack)];
            $current['method'] = $node->name->toString();
            $current['methodKey'] = NodeKeys::normalize('method:'.$current['fqcn'].'::'.$node->name->toString());
            if ($current['eloquent'] && preg_match('/^scope([A-Z].*)$/', $node->name->toString(), $match) === 1) {
                $methodNode = $extraction->nodes[$current['methodKey']] ?? null;
                if ($methodNode !== null) {
                    $methodNode->metadata['eloquent_role'] = 'query_scope';
                    $methodNode->metadata['scope_name'] = lcfirst($match[1]);
                }
            }
            if ($current['eloquent']) {
                $this->readAccessor($node, $extraction, $current['key']);
                $this->readCastsReturn($node, $extraction, $current['key']);
            }
        }

        $current = $this->stack[array_key_last($this->stack)] ?? null;
        $source = $current['methodKey'] ?? $current['key'] ?? null;
        if ($source === null) {
            return;
        }

        if ($node instanceof StaticCall) {
            $this->readStatic($node, $extraction, $source);
        }
        if ($node instanceof MethodCall) {
            $this->readContainer($node, $extraction, $source);
            $this->readControllerMiddleware($node, $extraction, $current);
        }
    }

    public function leave(Node $node): void
    {
        if ($node instanceof ClassLike && isset($node->namespacedName)) {
            array_pop($this->stack);
        }
        if ($node instanceof ClassMethod && $this->stack !== []) {
            $current = &$this->stack[array_key_last($this->stack)];
            $current['method'] = null;
            $current['methodKey'] = null;
        }
    }

    private function readStatic(StaticCall $call, FileExtraction $extraction, string $source): void
    {
        if (! $call->name instanceof Identifier || ! $call->class instanceof Name) {
            return;
        }
        $class = $call->class->toString();
        $method = $call->name->toString();
        $short = $call->class->getLast();

        if ($method === 'observe') {
            $observer = $this->classConst($call->args[0]->value ?? null);
            if ($observer !== null) {
                $this->edge($extraction, 'class:'.$observer, 'class:'.$class, 'class', $class, 'observes', 0.9, [
                    'resolution' => 'model_observer',
                    'resolution_state' => 'resolved',
                    'evidence_line' => $call->getStartLine(),
                ]);
            }

            return;
        }

        if ($method === 'listen' && ($short === 'Event' || str_ends_with($class, '\\Event'))) {
            $event = $this->classConst($call->args[0]->value ?? null);
            $listener = $this->classConst($call->args[1]->value ?? null);
            if ($event !== null && $listener !== null) {
                $this->edge($extraction, 'class:'.$listener, 'class:'.$event, 'event', $event, 'listens_to_event', 0.9, [
                    'resolution' => 'event_listen',
                    'resolution_state' => 'resolved',
                    'evidence_line' => $call->getStartLine(),
                ]);
            }

            return;
        }

        if ($method === 'policy' && ($short === 'Gate' || str_ends_with($class, '\\Gate'))) {
            $model = $this->classConst($call->args[0]->value ?? null);
            $policy = $this->classConst($call->args[1]->value ?? null);
            if ($model !== null && $policy !== null) {
                $this->edge($extraction, 'class:'.$policy, 'class:'.$model, 'class', $model, 'depends_on', 0.9, [
                    'via' => 'policy_map',
                    'resolution' => 'policy_map',
                    'resolution_state' => 'resolved',
                    'evidence_line' => $call->getStartLine(),
                ]);
            }
        }
    }

    private function readContainer(MethodCall $call, FileExtraction $extraction, string $source): void
    {
        if (! $call->name instanceof Identifier) {
            return;
        }
        $name = $call->name->toString();
        if (in_array($name, ['bind', 'singleton', 'scoped', 'bindIf'], true) && $this->isContainerReceiver($call->var)) {
            $this->readBinding($call, $extraction, $source, $name);

            return;
        }
        if ($name !== 'give') {
            return;
        }
        $needs = $call->var instanceof MethodCall ? $call->var : null;
        $when = $needs?->var instanceof MethodCall ? $needs->var : null;
        if ($needs === null || $when === null || ! $when->name instanceof Identifier || ! $needs->name instanceof Identifier) {
            return;
        }
        if (strtolower($when->name->toString()) !== 'when' || strtolower($needs->name->toString()) !== 'needs' || ! $this->isContainerReceiver($when->var)) {
            return;
        }
        $context = $this->classConst($when->args[0]->value ?? null);
        $abstract = $this->classConst($needs->args[0]->value ?? null);
        $concrete = $this->concrete($call->args[0]->value ?? null);
        if ($context === null || $abstract === null || $concrete === null) {
            return;
        }
        $this->edge($extraction, $source, 'class:'.$concrete['class'], 'class', $concrete['class'], 'contextual_binding', min(0.85, $concrete['confidence']), [
            'context' => $context,
            'abstract' => $abstract,
            'resolution' => 'contextual_binding',
            'resolution_state' => $concrete['via'] === 'closure_new' ? 'partially_resolved' : 'resolved',
            'evidence_line' => $call->getStartLine(),
        ]);
    }

    private function readBinding(MethodCall $call, FileExtraction $extraction, string $source, string $lifetime): void
    {
        $abstract = $this->bindingKey($call->args[0]->value ?? null);
        if ($abstract === null) {
            return;
        }
        $second = $call->args[1]->value ?? null;
        $concrete = $this->concrete($second);
        if ($concrete === null && $second === null && $abstract['class'] !== null && $lifetime !== 'bindIf') {
            $concrete = ['class' => $abstract['class'], 'confidence' => 0.9, 'via' => 'self_binding'];
        }
        if ($concrete === null) {
            return;
        }
        $metadata = [
            'abstract' => $abstract['class'] ?? $abstract['key'],
            'abstract_kind' => $abstract['class'] !== null ? 'class' : 'container_key',
            'lifetime' => $lifetime,
            'via' => $concrete['via'],
            'resolution' => 'container_binding',
            'resolution_state' => 'resolved',
            'evidence_line' => $call->getStartLine(),
        ];
        $this->edge($extraction, $source, 'class:'.$concrete['class'], 'class', $concrete['class'], 'binds', $concrete['confidence'], $metadata);
        if ($abstract['class'] !== null && $abstract['class'] !== $concrete['class']) {
            $this->edge($extraction, 'class:'.$abstract['class'], 'class:'.$concrete['class'], 'class', $concrete['class'], 'binds', $concrete['confidence'], $metadata + [
                'declared_in' => $source,
            ]);
        }
    }

    /** @param array{key: string, fqcn: string, method: ?string, methodKey: ?string, eloquent: bool, provider: bool}|null $current */
    private function readControllerMiddleware(MethodCall $call, FileExtraction $extraction, ?array $current): void
    {
        if ($current === null || ! $call->name instanceof Identifier || $call->name->toString() !== 'middleware') {
            return;
        }
        if (! $call->var instanceof Variable || $call->var->name !== 'this') {
            return;
        }
        $node = $extraction->nodes[$current['key']] ?? null;
        $value = $call->args[0]->value ?? null;
        if (! $node instanceof \ProjectMemory\Data\ExtractedNode || ! $value instanceof String_) {
            return;
        }
        $aliases = $node->metadata['middleware_aliases'] ?? [];
        $aliases[] = $value->value;
        $node->metadata['middleware_aliases'] = array_values(array_unique($aliases));
        $node->metadata['middleware_alias_state'] = 'unresolved_alias';
    }

    private function readObservedBy(ClassLike $node, FileExtraction $extraction, string $key, string $fqcn): void
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (! $attribute instanceof Attribute || ! str_ends_with($attribute->name->toString(), 'ObservedBy')) {
                    continue;
                }
                foreach ($attribute->args as $argument) {
                    if (! $argument instanceof Arg) {
                        continue;
                    }
                    $observers = $argument->value instanceof Array_ ? $argument->value->items : [new Node\ArrayItem($argument->value)];
                    foreach ($observers as $item) {
                        $observer = $this->classConst($item?->value);
                        if ($observer === null) {
                            continue;
                        }
                        $this->edge($extraction, 'class:'.$observer, 'class:'.$fqcn, 'class', $fqcn, 'observes', 0.9, [
                            'resolution' => 'observed_by_attribute',
                            'resolution_state' => 'resolved',
                            'evidence_line' => $attribute->getStartLine(),
                        ]);
                    }
                }
            }
        }
    }

    private function readPolicies(ClassLike $node, FileExtraction $extraction, string $sourceKey): void
    {
        if (! method_exists($node, 'getProperties')) {
            return;
        }
        foreach ($node->getProperties() as $property) {
            if ($property->props[0]->name->toString() !== 'policies' || ! $property->props[0]->default instanceof Array_) {
                continue;
            }
            foreach ($property->props[0]->default->items as $item) {
                if ($item === null) {
                    continue;
                }
                $model = $this->classConst($item->key);
                $policy = $this->classConst($item->value);
                if ($model === null || $policy === null) {
                    continue;
                }
                $this->edge($extraction, 'class:'.$policy, 'class:'.$model, 'class', $model, 'depends_on', 0.9, [
                    'via' => 'policy_map',
                    'resolution' => 'policy_map',
                    'resolution_state' => 'resolved',
                    'declared_in' => $sourceKey,
                    'evidence_line' => $property->getStartLine(),
                ]);
            }
        }
    }

    private function readEloquentMetadata(ClassLike $node, \ProjectMemory\Data\ExtractedNode $classNode): void
    {
        $casts = $classNode->metadata['casts'] ?? [];
        if (method_exists($node, 'getProperties')) {
            foreach ($node->getProperties() as $property) {
                if ($property->props[0]->name->toString() === 'casts' && $property->props[0]->default instanceof Array_) {
                    $casts = array_replace($casts, $this->castMap($property->props[0]->default));
                }
            }
        }
        if ($casts !== []) {
            $classNode->metadata['casts'] = $casts;
        }
    }

    private function readCastsReturn(ClassMethod $method, FileExtraction $extraction, string $classKey): void
    {
        if ($method->name->toString() !== 'casts') {
            return;
        }
        $classNode = $extraction->nodes[$classKey] ?? null;
        foreach ($method->stmts ?? [] as $statement) {
            if ($statement instanceof Return_ && $statement->expr instanceof Array_ && $classNode !== null) {
                $classNode->metadata['casts'] = array_replace($classNode->metadata['casts'] ?? [], $this->castMap($statement->expr));
            }
        }
    }

    /** @return array<string, string> */
    private function castMap(Array_ $array): array
    {
        $casts = [];
        foreach ($array->items as $item) {
            if ($item?->key instanceof String_) {
                $value = $item->value instanceof String_ ? $item->value->value : ($this->classConst($item->value) ?? null);
                if (is_string($value) && $value !== '') {
                    $casts[$item->key->value] = $value;
                }
            }
        }

        return $casts;
    }

    private function readAccessor(ClassMethod $method, FileExtraction $extraction, string $classKey): void
    {
        $name = $method->name->toString();
        $accessor = null;
        if (preg_match('/^(?:get|set)(.+)Attribute$/', $name, $match) === 1) {
            $accessor = $match[1];
        }
        $return = $method->returnType instanceof Name ? $method->returnType->toString() : null;
        if ($accessor === null && is_string($return) && str_ends_with($return, '\\Attribute')) {
            $accessor = $name;
        }
        if ($accessor === null) {
            return;
        }
        $classNode = $extraction->nodes[$classKey] ?? null;
        if ($classNode === null) {
            return;
        }
        $names = $classNode->metadata['accessors'] ?? [];
        $names[] = $accessor;
        $classNode->metadata['accessors'] = array_values(array_unique($names));
    }

    /** @return array{accessor: string, kind: string}|null */
    private function facadeAccessor(Class_ $class): ?array
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== 'getfacadeaccessor') {
                continue;
            }
            foreach ($method->stmts ?? [] as $statement) {
                if (! $statement instanceof Return_) {
                    continue;
                }
                if ($statement->expr instanceof String_ && $statement->expr->value !== '') {
                    return [
                        'accessor' => $statement->expr->value,
                        'kind' => str_contains($statement->expr->value, '\\') ? 'class' : 'container_key',
                    ];
                }
                $className = $this->classConst($statement->expr);
                if ($className !== null) {
                    return ['accessor' => $className, 'kind' => 'class'];
                }
            }
        }

        return null;
    }

    private function isFacadeParent(?string $parent): bool
    {
        return is_string($parent) && str_ends_with($parent, '\\Facade');
    }

    private function isContainerReceiver(Node $node): bool
    {
        if ($node instanceof PropertyFetch && $node->var instanceof Variable && $node->var->name === 'this' && $node->name instanceof Identifier) {
            return $node->name->toString() === 'app';
        }

        return $node instanceof FuncCall && $node->name instanceof Name && $node->name->toString() === 'app' && $node->args === [];
    }

    /** @return array{class: ?string, key: ?string}|null */
    private function bindingKey(?Node $node): ?array
    {
        $class = $this->classConst($node);
        if ($class !== null) {
            return ['class' => $class, 'key' => null];
        }
        if ($node instanceof String_ && $node->value !== '') {
            return str_contains($node->value, '\\')
                ? ['class' => ltrim($node->value, '\\'), 'key' => null]
                : ['class' => null, 'key' => $node->value];
        }

        return null;
    }

    /** @return array{class: string, confidence: float, via: string}|null */
    private function concrete(?Node $node): ?array
    {
        if ($node === null) {
            return null;
        }
        $class = $this->classConst($node);
        if ($class !== null) {
            return ['class' => $class, 'confidence' => 0.95, 'via' => 'class_const'];
        }
        if ($node instanceof String_ && str_contains($node->value, '\\')) {
            return ['class' => ltrim($node->value, '\\'), 'confidence' => 0.8, 'via' => 'class_string'];
        }
        if ($node instanceof Closure || $node instanceof Expr\ArrowFunction) {
            $created = $this->soleInstantiatedClass($node);
            if ($created !== null) {
                return ['class' => $created, 'confidence' => 0.85, 'via' => 'closure_new'];
            }
        }

        return null;
    }

    private function soleInstantiatedClass(Node $node): ?string
    {
        $found = [];
        $walk = function (array|Node|null $current) use (&$walk, &$found): void {
            if ($found !== null && count($found) > 1) {
                return;
            }
            if (is_array($current)) {
                foreach ($current as $child) {
                    $walk($child);
                }

                return;
            }
            if (! $current instanceof Node || $current instanceof ClassLike || $current instanceof Node\Stmt\Function_) {
                return;
            }
            if ($current instanceof New_ && $current->class instanceof Name) {
                $found[] = $current->class->toString();
            }
            foreach ($current->getSubNodeNames() as $name) {
                if (is_array($current->$name) || $current->$name instanceof Node) {
                    $walk($current->$name);
                }
            }
        };
        $walk($node instanceof Closure ? $node->stmts : ($node instanceof Expr\ArrowFunction ? $node->expr : $node));
        $found = array_values(array_unique($found));

        return count($found) === 1 ? $found[0] : null;
    }

    private function classConst(?Node $node): ?string
    {
        if (! $node instanceof ClassConstFetch || ! $node->class instanceof Name || ! $node->name instanceof Identifier) {
            return null;
        }

        return strtolower($node->name->toString()) === 'class' ? $node->class->toString() : null;
    }

    private function edge(
        FileExtraction $extraction,
        string $source,
        string $target,
        string $type,
        string $symbol,
        string $relationship,
        float $confidence,
        array $metadata,
    ): void {
        $extraction->addEdge(new ExtractedEdge(
            NodeKeys::normalize($source),
            NodeKeys::normalize($target),
            $type,
            $symbol,
            $relationship,
            $confidence,
            $metadata,
        ));
    }
}
