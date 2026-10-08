<?php

namespace ProjectMemory\Indexing;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
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
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\ExtractedNode;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\NodeKeys;

class PhpSymbolVisitor extends NodeVisitorAbstract
{
    /** @var array<string, int>|null */
    private static ?array $internalFunctions = null;

    /** @var list<array{key: string, fqcn: string, properties: array<string, string>, eloquent: bool, relations: array<string, string>}> */
    private array $classes = [];

    public function __construct(
        private readonly FileExtraction $extraction,
        private readonly string $code,
        private readonly FileHasher $hasher,
    ) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof ClassLike) {
            $this->enterClass($node);
        }

        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->enterCallable($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classes);
        }

        return null;
    }

    private function enterClass(ClassLike $node): void
    {
        $fqcn = $node->namespacedName?->toString()
            ?? 'anonymous:'.$this->extraction->relativePath.':'.$node->getStartLine();
        $short = $node->name?->toString() ?? 'anonymous';
        $type = $this->classType($node, $short);
        // A class has one identity regardless of its Laravel role or kind. This
        // also unifies type hints referring to interfaces with their declaration.
        $key = NodeKeys::normalize('class:'.$fqcn);
        $eloquent = $this->isEloquentParent($this->parentName($node));
        $properties = $this->propertyTypes($node);
        $summary = trim($type.' '.$fqcn.($this->extendsName($node, '') ? ' extends '.$this->parentName($node) : ''));

        $this->extraction->addNode(new ExtractedNode(
            $key,
            $type,
            $fqcn,
            $node->getStartLine(),
            $node->getEndLine(),
            $this->hasher->lines($this->code, $node->getStartLine(), $node->getEndLine()),
            $summary,
            [
                'short_name' => $short,
                'extends' => $this->parentName($node),
                'implements' => $this->nameList($node instanceof Class_ || $node instanceof Enum_ ? $node->implements : []),
                'interface_extends' => $this->nameList($node instanceof Interface_ ? $node->extends : []),
                'trait_adaptations' => array_sum(array_map(fn ($use) => count($use->adaptations), $node->getTraitUses())),
                'eloquent_model' => $eloquent,
                'php_kind' => $node instanceof Enum_ ? 'enum' : $type,
                'stub' => false,
            ],
        ));

        if ($node instanceof Class_ && $node->extends instanceof Name) {
            $this->relate($key, 'class:'.$node->extends->toString(), 'class', $node->extends->toString(), 'extends', 0.95);
        }

        foreach ($node instanceof Interface_ ? $node->extends : [] as $interface) {
            $this->relate($key, 'class:'.$interface->toString(), 'interface', $interface->toString(), 'extends', 0.95);
        }

        $implements = $node instanceof Class_ || $node instanceof Enum_ ? $node->implements : [];
        foreach ($implements as $interface) {
            if ($interface instanceof Name) {
                $this->relate($key, 'class:'.$interface->toString(), 'interface', $interface->toString(), 'implements', 0.95);
            }
        }

        if (method_exists($node, 'getTraitUses')) {
            foreach ($node->getTraitUses() as $traitUse) {
                foreach ($traitUse->traits as $trait) {
                    $this->relate($key, 'class:'.$trait->toString(), 'trait', $trait->toString(), 'uses_trait', 0.95);
                }
            }
        }

        foreach ($node->getProperties() as $property) {
            foreach ($this->classTypes($property->type) as $propertyType) {
                $propertyType = $this->declaredClassType($propertyType, $node);
                if ($propertyType === null) {
                    continue;
                }
                $this->relate($key, 'class:'.$propertyType, 'class', $propertyType, 'depends_on', 0.95, ['via' => 'property_type']);
            }
        }

        if ($eloquent) {
            $explicitTable = false;
            foreach ($node->getProperties() as $property) {
                if ($property->props[0]->name->toString() !== 'table') {
                    continue;
                }

                $default = $property->props[0]->default ?? null;
                if ($default instanceof String_ && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $default->value)) {
                    $explicitTable = true;
                    $this->relate($key, 'table:'.$default->value, 'table', $default->value, 'uses_table', 0.9, [
                        'shared_target' => true,
                    ]);
                }
            }
            if (! $explicitTable) {
                $table = Str::snake(Str::pluralStudly($short));
                $this->relate($key, 'table:'.$table, 'table', $table, 'uses_table', 0.7, ['shared_target' => true, 'inferred' => true, 'via' => 'eloquent_convention']);
            }
        }

        $this->readListenMap($node, $key);
        $this->classes[] = [
            'key' => $key,
            'fqcn' => $fqcn,
            'properties' => $properties,
            'eloquent' => $eloquent,
            'relations' => $eloquent ? $this->relationMethods($node) : [],
        ];
    }

    private function enterCallable(ClassMethod|Function_ $node): void
    {
        $current = $this->classes[array_key_last($this->classes)] ?? null;
        $name = $node->name->toString();

        if ($node instanceof ClassMethod && $current !== null) {
            $symbol = $current['fqcn'].'::'.$name;
            $key = NodeKeys::normalize('method:'.$symbol);
            $owner = $current['fqcn'];
        } elseif ($node instanceof Function_) {
            $namespaced = $node->namespacedName?->toString() ?? $name;
            $symbol = $namespaced;
            $key = NodeKeys::normalize('function:'.$symbol);
            $owner = null;
        } else {
            return;
        }

        $signature = $this->signature($node);
        $this->extraction->addNode(new ExtractedNode(
            $key,
            $node instanceof ClassMethod ? 'method' : 'function',
            $symbol,
            $node->getStartLine(),
            $node->getEndLine(),
            $this->hasher->lines($this->code, $node->getStartLine(), $node->getEndLine()),
            $signature,
            [
                'signature' => $signature,
                'visibility' => $node instanceof ClassMethod ? $this->visibility($node) : 'function',
                'parameters' => $this->parameters($node),
                'return_type' => $this->typeName($node->returnType),
                'class' => $owner,
                'class_key' => $node instanceof ClassMethod ? ($current['key'] ?? null) : null,
                'stub' => false,
                'unresolved_calls' => 0,
            ],
        ));

        if ($node instanceof ClassMethod && $current !== null) {
            $this->relate($current['key'], $key, 'method', $symbol, 'contains', 1.0);
        }

        $locals = [];
        foreach ($node->params as $param) {
            $type = $this->resolveSpecialClass($this->namedType($param->type), $node instanceof ClassMethod ? $current : null);
            if ($type !== null && $param->var instanceof Variable && is_string($param->var->name)) {
                $locals[$param->var->name] = $type;
            }
            foreach ($this->classTypes($param->type) as $dependency) {
                $dependency = $this->resolveSpecialClass($dependency, $node instanceof ClassMethod ? $current : null);
                if ($dependency === null) {
                    continue;
                }
                $this->relate($key, 'class:'.$dependency, 'class', $dependency, 'depends_on', 0.95, [
                    'via' => 'parameter',
                    'parameter' => $param->var->name,
                ]);
            }
        }

        foreach ($this->classTypes($node->returnType) as $dependency) {
            $dependency = $this->resolveSpecialClass($dependency, $node instanceof ClassMethod ? $current : null);
            if ($dependency === null) {
                continue;
            }
            $this->relate($key, 'class:'.$dependency, 'class', $dependency, 'depends_on', 0.95, ['via' => 'return_type']);
        }

        $this->scanBody($node, $key, $locals, $node instanceof ClassMethod ? $current : null);
    }

    /**
     * @param  array<string, string>  $locals
     * @param  array{key: string, fqcn: string, properties: array<string, string>, eloquent: bool, relations?: array<string, string>}|null  $current
     */
    private function scanBody(ClassMethod|Function_ $node, string $methodKey, array $locals, ?array $current): void
    {
        $unresolved = 0;
        $this->walkBody($node->stmts ?? [], $locals, $current, function (Node $call, array $types) use ($methodKey, $current, &$unresolved): void {
            if ($call instanceof New_ || $call instanceof ClassConstFetch || $call instanceof Expr\Instanceof_) {
                $class = $call instanceof New_ ? $this->instantiatedClass($call) : ($call->class instanceof Name ? $call->class->toString() : null);
                $class = $this->resolveSpecialClass($class, $current);
                if ($class !== null) {
                    $this->relate($methodKey, 'class:'.$class, 'class', $class, 'depends_on', 0.9, ['via' => $call instanceof New_ ? 'new' : 'class_reference']);
                }
            }

            if ($call instanceof StaticCall) {
                if (! $call->name instanceof Identifier || ! $call->class instanceof Name) {
                    $unresolved++;

                    return;
                }

                $class = $call->class->toString();
                $method = $call->name->toString();

                $special = strtolower($class);
                if (in_array($special, ['self', 'static', 'parent'], true)) {
                    $resolved = $special === 'parent' ? $this->parentOfCurrent($current) : ($current['fqcn'] ?? null);
                    if ($resolved === null) {
                        $unresolved++;

                        return;
                    }
                    $confidence = $special === 'static' ? 0.4 : 0.85;
                    $this->relate($methodKey, 'method:'.$resolved.'::'.$method, 'method', $resolved.'::'.$method, 'calls', $confidence, [
                        'late_static_binding' => $special === 'static',
                    ]);

                    return;
                }

                if ($method === 'dispatch' && str_ends_with($class, 'Event')) {
                    $this->relate($methodKey, 'class:'.$class, 'event', $class, 'dispatches_event', 0.7);
                } elseif ($method === 'dispatch') {
                    $this->relate($methodKey, 'class:'.$class, 'class', $class, 'depends_on', 0.75, ['via' => 'dispatch']);
                } else {
                    $this->relate($methodKey, 'method:'.$class.'::'.$method, 'method', $class.'::'.$method, 'calls', 0.85);
                }
            }

            if ($call instanceof MethodCall || $call instanceof Expr\NullsafeMethodCall) {
                if (! $call->name instanceof Identifier) {
                    $unresolved++;

                    return;
                }

                $method = $call->name->toString();
                $target = $this->receiverType($call->var, $types, $current);

                if ($target !== null) {
                    $metadata = ['inferred' => true];
                    if (str_starts_with($target, 'Illuminate\\')) {
                        $metadata['resolution'] = 'framework_type';
                        $metadata['resolution_state'] = 'external';
                    }
                    $this->relate($methodKey, 'method:'.$target.'::'.$method, 'method', $target.'::'.$method, 'calls', 0.75, $metadata);
                } elseif ($call->var instanceof Variable && $call->var->name === 'this' && $current !== null) {
                    $this->relate($methodKey, 'method:'.$current['fqcn'].'::'.$method, 'method', $current['fqcn'].'::'.$method, 'calls', 0.85);
                } else {
                    $unresolved++;
                }

                if (($current['eloquent'] ?? false) && $this->isRelation($method) && $call->var instanceof Variable && $call->var->name === 'this') {
                    $related = $this->classConst($call->args[0]->value ?? null);
                    if ($related !== null) {
                        $this->relate($methodKey, 'class:'.$related, 'class', $related, 'uses_model', 0.85, [
                            'relation' => $method,
                            'resolution' => 'eloquent_relation',
                            'resolution_state' => 'resolved',
                        ]);
                    }
                    if ($method === 'belongsToMany') {
                        $pivot = $call->args[1]->value ?? null;
                        if ($pivot instanceof String_ && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $pivot->value) === 1) {
                            $this->relate($methodKey, 'table:'.$pivot->value, 'table', $pivot->value, 'uses_table', 0.9, [
                                'shared_target' => true,
                                'via' => 'pivot',
                                'resolution' => 'eloquent_pivot',
                                'resolution_state' => 'resolved',
                            ]);
                        }
                    }
                }
            }

            if ($call instanceof FuncCall) {
                if (! $call->name instanceof Name) {
                    $unresolved++;

                    return;
                }

                $function = $call->name->toString();
                self::$internalFunctions ??= array_flip(get_defined_functions()['internal']);
                if ($call->name instanceof Name\FullyQualified && isset(self::$internalFunctions[strtolower($function)])) {
                    return;
                }
                $first = $call->args[0]->value ?? null;

                if ($function === 'view' && $first instanceof String_) {
                    $this->relate($methodKey, 'view:'.$first->value, 'blade_view', $first->value, 'renders_view', 0.9);
                } elseif ($function === 'route' && $first instanceof String_) {
                    $this->relate($methodKey, 'route:'.$first->value, 'route', $first->value, 'references_route', 0.8);
                } elseif ($function === 'event') {
                    $event = $this->instantiatedClass($first);
                    if ($event !== null) {
                        $this->relate($methodKey, 'class:'.$event, 'event', $event, 'dispatches_event', 0.7);
                    } else {
                        $unresolved++;
                    }
                } elseif (in_array($function, ['app', 'resolve'], true)) {
                    $class = $this->classConst($first);
                    if ($class !== null) {
                        $this->relate($methodKey, 'class:'.$class, 'class', $class, 'depends_on', 0.8, ['via' => $function]);
                    }
                }

                // Fully qualified/imported function names are exact. Unqualified
                // calls inside namespaces retain PHP's namespace/global fallback.
                $namespaced = $call->name->getAttribute('namespacedName');
                $candidate = $namespaced instanceof Name ? $namespaced->toString() : $function;
                $this->relate($methodKey, 'function:'.$candidate, 'function', $candidate, 'calls', $namespaced instanceof Name ? 0.65 : 0.95, [
                    'resolution' => $namespaced instanceof Name ? 'namespace_fallback' : 'resolved_name',
                ]);
                if ($candidate !== $function) {
                    $this->relate($methodKey, 'function:'.$function, 'function', $function, 'calls', 0.5, ['resolution' => 'global_fallback']);
                }
            }
        });
        $existing = $this->extraction->nodes[$methodKey] ?? null;
        if ($existing !== null) {
            $existing->metadata['unresolved_calls'] = $unresolved;
        }
    }

    /**
     * Walk once in evaluation order, without attributing nested declarations to
     * their enclosing method. Closure/branch assignments cannot leak types into
     * their parent scope; unknown assignments invalidate previous inference.
     *
     * @param  array<Node|null>|Node|null  $nodes
     * @param  array<string, string>  $locals
     */
    private function walkBody(array|Node|null $nodes, array &$locals, ?array $current, callable $visit): void
    {
        if (is_array($nodes)) {
            foreach ($nodes as $child) {
                $this->walkBody($child, $locals, $current, $visit);
            }

            return;
        }
        if (! $nodes instanceof Node || $nodes instanceof ClassLike || $nodes instanceof Function_) {
            return;
        }
        if ($nodes instanceof Assign) {
            $this->walkBody($nodes->expr, $locals, $current, $visit);
            if ($nodes->var instanceof Variable && is_string($nodes->var->name)) {
                $type = $this->receiverType($nodes->expr, $locals, $current);
                unset($locals[$nodes->var->name]);
                if ($type !== null) {
                    $locals[$nodes->var->name] = $type;
                }
            }

            return;
        }
        if ($nodes instanceof Expr\AssignRef || $nodes instanceof Expr\AssignOp) {
            foreach ($nodes->getSubNodeNames() as $name) {
                if ($nodes->$name instanceof Node) {
                    $this->walkBody($nodes->$name, $locals, $current, $visit);
                }
            }
            if ($nodes->var instanceof Variable && is_string($nodes->var->name)) {
                unset($locals[$nodes->var->name]);
            }

            return;
        }
        if ($nodes instanceof Node\Stmt\Unset_) {
            foreach ($nodes->vars as $variable) {
                if ($variable instanceof Variable && is_string($variable->name)) {
                    unset($locals[$variable->name]);
                }
            }

            return;
        }
        if ($nodes instanceof Expr\Closure || $nodes instanceof Expr\ArrowFunction) {
            // Ordinary closures capture only declared `use` variables; arrows
            // capture their surrounding scope implicitly.
            $scoped = $nodes instanceof Expr\ArrowFunction ? $locals : [];
            foreach ($nodes instanceof Expr\Closure ? $nodes->uses : [] as $use) {
                if ($use->var instanceof Variable && is_string($use->var->name) && isset($locals[$use->var->name])) {
                    $scoped[$use->var->name] = $locals[$use->var->name];
                }
            }
            foreach ($nodes->params as $param) {
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    unset($scoped[$param->var->name]);
                    if (($type = $this->namedType($param->type)) !== null) {
                        $scoped[$param->var->name] = $type;
                    }
                }
            }
            $this->walkBody($nodes instanceof Expr\Closure ? $nodes->stmts : $nodes->expr, $scoped, $current, $visit);

            return;
        }
        if ($nodes instanceof Node\Stmt\If_ || $nodes instanceof Node\Stmt\Switch_ || $nodes instanceof Node\Stmt\TryCatch
            || $nodes instanceof Node\Stmt\Foreach_ || $nodes instanceof Node\Stmt\For_ || $nodes instanceof Node\Stmt\While_ || $nodes instanceof Node\Stmt\Do_) {
            $before = $locals;
            $scoped = $locals;
            foreach ($nodes->getSubNodeNames() as $name) {
                $branch = $before;
                if (is_array($nodes->$name) || $nodes->$name instanceof Node) {
                    $this->walkBody($nodes->$name, $branch, $current, $visit);
                }
                foreach ($scoped as $variable => $type) {
                    if (($branch[$variable] ?? null) !== $type) {
                        unset($scoped[$variable]);
                    }
                }
            }
            $locals = $scoped;

            return;
        }
        foreach ($nodes->getSubNodeNames() as $name) {
            if (is_array($nodes->$name) || $nodes->$name instanceof Node) {
                $this->walkBody($nodes->$name, $locals, $current, $visit);
            }
        }
        $visit($nodes, $locals);
    }

    private function resolveSpecialClass(?string $class, ?array $current): ?string
    {
        return match (strtolower($class ?? '')) {
            'self', 'static' => $current['fqcn'] ?? null,
            'parent' => $this->parentOfCurrent($current),
            default => $class,
        };
    }

    private function readListenMap(ClassLike $node, string $sourceKey): void
    {
        if (! method_exists($node, 'getProperties')) {
            return;
        }

        foreach ($node->getProperties() as $property) {
            if ($property->props[0]->name->toString() !== 'listen') {
                continue;
            }

            $default = $property->props[0]->default ?? null;
            if (! $default instanceof Expr\Array_) {
                continue;
            }

            foreach ($default->items as $item) {
                if ($item === null) {
                    continue;
                }

                $event = $this->classConst($item->key);
                $listeners = $item->value instanceof Expr\Array_ ? $item->value->items : [];

                if ($event === null) {
                    continue;
                }

                foreach ($listeners as $listenerItem) {
                    $listener = $this->classConst($listenerItem?->value);
                    if ($listener === null) {
                        continue;
                    }

                    $listenerKey = 'class:'.$listener;

                    $this->relate(
                        $listenerKey,
                        'class:'.$event,
                        'event',
                        $event,
                        'listens_to_event',
                        0.9,
                        ['declared_in' => $sourceKey]
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $locals
     * @param  array{key: string, fqcn: string, properties: array<string, string>, eloquent: bool}|null  $current
     */
    private function receiverType(Node $receiver, array $locals, ?array $current): ?string
    {
        if ($receiver instanceof New_) {
            return $this->resolveSpecialClass($receiver->class instanceof Name ? $receiver->class->toString() : null, $current);
        }
        if ($receiver instanceof FuncCall && $receiver->name instanceof Name && in_array($receiver->name->toString(), ['app', 'resolve'], true)) {
            return $this->classConst($receiver->args[0]->value ?? null);
        }
        if ($receiver instanceof MethodCall || $receiver instanceof Expr\NullsafeMethodCall) {
            if ($receiver->name instanceof Identifier) {
                $method = $receiver->name->toString();
                if ($receiver->var instanceof Variable && $receiver->var->name === 'this' && isset($current['relations'][$method])) {
                    return 'Illuminate\\Database\\Eloquent\\Relations\\Relation';
                }
                $inner = $this->receiverType($receiver->var, $locals, $current);
                $chain = LaravelFluentMethods::chainType($method, $inner);
                if ($chain !== null) {
                    return $chain;
                }
            }
        }
        if ($receiver instanceof StaticCall && $receiver->class instanceof Name && $receiver->name instanceof Identifier) {
            $class = $this->resolveSpecialClass($receiver->class->toString(), $current);
            $known = $class !== null ? ($this->extraction->nodes[NodeKeys::normalize('class:'.$class)]->metadata['eloquent_model'] ?? false) : false;
            if ($known) {
                $chain = LaravelFluentMethods::chainType($receiver->name->toString(), 'Illuminate\\Database\\Eloquent\\Model');
                if ($chain !== null) {
                    return $chain;
                }
            }
        }
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            if ($receiver->name === 'this') {
                return $current['fqcn'] ?? null;
            }

            return $locals[$receiver->name] ?? null;
        }

        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
            && $current !== null) {
            return $current['properties'][$receiver->name->toString()] ?? null;
        }

        return null;
    }

    private function instantiatedClass(?Node $node): ?string
    {
        if (! $node instanceof New_ || ! $node->class instanceof Name) {
            return null;
        }

        $name = $node->class->toString();

        return $name;
    }

    private function classConst(?Node $node): ?string
    {
        if (! $node instanceof ClassConstFetch || ! $node->class instanceof Name) {
            return null;
        }

        if (! $node->name instanceof Identifier || strtolower($node->name->toString()) !== 'class') {
            return null;
        }

        return $node->class->toString();
    }

    /**
     * @return array<string, string>
     */
    private function propertyTypes(ClassLike $node): array
    {
        $types = [];

        if (! method_exists($node, 'getProperties')) {
            return $types;
        }

        foreach ($node->getProperties() as $property) {
            $type = $this->namedType($property->type);
            $type = $this->declaredClassType($type, $node);
            if ($type === null) {
                continue;
            }

            foreach ($property->props as $prop) {
                $types[$prop->name->toString()] = $type;
            }
        }

        foreach ($node->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param->flags !== 0 && $param->var instanceof Variable && is_string($param->var->name) && ($type = $this->declaredClassType($this->namedType($param->type), $node)) !== null) {
                    $types[$param->var->name] = $type;
                }
            }
        }

        return $types;
    }

    private function declaredClassType(?string $type, ClassLike $node): ?string
    {
        return match (strtolower($type ?? '')) {
            'self', 'static' => $node->namespacedName?->toString(),
            'parent' => $this->parentName($node),
            default => $type,
        };
    }

    private function classType(ClassLike $node, string $short): string
    {
        if ($node instanceof Interface_) {
            return 'interface';
        }

        if ($node instanceof Trait_) {
            return 'trait';
        }
        if ($node instanceof Enum_) {
            return 'enum';
        }

        if (str_ends_with($short, 'Policy')) {
            return 'policy';
        }

        if (str_ends_with($short, 'Listener')) {
            return 'listener';
        }

        if (str_ends_with($short, 'Job') || $this->implementsName($node, 'ShouldQueue')) {
            return 'job';
        }

        if (str_ends_with($short, 'Event')) {
            return 'event';
        }

        return 'class';
    }

    private function implementsName(ClassLike $node, string $suffix): bool
    {
        if (! $node instanceof Class_) {
            return false;
        }

        foreach ($node->implements as $interface) {
            if ($interface->getLast() === $suffix) {
                return true;
            }
        }

        return false;
    }

    private function extendsName(ClassLike $node, string $suffix): bool
    {
        if (! $node instanceof Class_ || ! $node->extends instanceof Name) {
            return false;
        }

        if ($suffix === '') {
            return true;
        }

        return str_ends_with($node->extends->toString(), '\\'.$suffix) || $node->extends->toString() === $suffix;
    }

    private function parentName(ClassLike $node): ?string
    {
        if ($node instanceof Class_ && $node->extends instanceof Name) {
            return $node->extends->toString();
        }

        return null;
    }

    /**
     * @param  array{key: string, fqcn: string, properties: array<string, string>, eloquent: bool}|null  $current
     */
    private function parentOfCurrent(?array $current): ?string
    {
        if ($current === null) {
            return null;
        }

        $node = $this->extraction->nodes[$current['key']] ?? null;

        return $node?->metadata['extends'] ?? null;
    }

    /**
     * @param  list<Name>  $names
     * @return list<string>
     */
    private function nameList(array $names): array
    {
        return array_values(array_map(fn (Name $name) => $name->toString(), $names));
    }

    private function signature(ClassMethod|Function_ $node): string
    {
        $prefix = $node instanceof ClassMethod ? $this->visibility($node).' function' : 'function';
        $params = [];

        foreach ($node->params as $param) {
            $type = $this->typeName($param->type);
            $piece = ($type !== null ? $type.' ' : '').'$'.$param->var->name;
            if ($param->default !== null) {
                $piece .= ' = '.$this->defaultLabel($param->default);
            }
            $params[] = $piece;
        }

        $return = $this->typeName($node->returnType);

        return $prefix.' '.$node->name->toString().'('.implode(', ', $params).')'.($return !== null ? ': '.$return : '');
    }

    private function defaultLabel(Node $default): string
    {
        if ($default instanceof String_) {
            return '…';
        }

        if ($default instanceof Expr\ConstFetch) {
            return $default->name->toString();
        }

        if ($default instanceof Expr\Array_ && $default->items === []) {
            return '[]';
        }

        return '…';
    }

    private function visibility(ClassMethod $node): string
    {
        if ($node->isPrivate()) {
            return 'private';
        }

        if ($node->isProtected()) {
            return 'protected';
        }

        return 'public';
    }

    /**
     * @return list<array{name: string, type: ?string}>
     */
    private function parameters(ClassMethod|Function_ $node): array
    {
        $parameters = [];

        foreach ($node->params as $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                continue;
            }

            $parameters[] = [
                'name' => $param->var->name,
                'type' => $this->typeName($param->type),
            ];
        }

        return $parameters;
    }

    private function typeName(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Node\NullableType) {
            $inner = $this->typeName($type->type);

            return $inner === null ? null : '?'.$inner;
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $separator = $type instanceof Node\UnionType ? '|' : '&';
            $parts = array_filter(array_map(fn (Node $part) => $this->typeName($part), $type->types));

            return $parts === [] ? null : implode($separator, $parts);
        }

        if ($type instanceof Identifier || $type instanceof Name) {
            return $type->toString();
        }

        return null;
    }

    private function namedType(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->namedType($type->type);
        }

        // A union or intersection is not one concrete receiver.
        return $type instanceof Name ? $type->toString() : null;
    }

    /** @return list<string> */
    private function classTypes(?Node $type): array
    {
        if ($type instanceof Node\NullableType) {
            return $this->classTypes($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];
            foreach ($type->types as $part) {
                $names = array_merge($names, $this->classTypes($part));
            }

            return array_values(array_unique($names));
        }

        return $type instanceof Name ? [$type->toString()] : [];
    }

    private function isEloquentParent(?string $parent): bool
    {
        return in_array($parent, [
            'Illuminate\\Database\\Eloquent\\Model',
            'Illuminate\\Foundation\\Auth\\User',
            'Illuminate\\Database\\Eloquent\\Relations\\Pivot',
        ], true);
    }

    /** @return array<string, string> */
    private function relationMethods(ClassLike $node): array
    {
        $relations = [];
        if (! method_exists($node, 'getMethods')) {
            return $relations;
        }
        foreach ($node->getMethods() as $method) {
            $related = $this->relationTarget($method->stmts ?? []);
            if ($related !== null) {
                $relations[$method->name->toString()] = $related;
            }
        }

        return $relations;
    }

    /** @param array<Node|null>|Node|null $nodes */
    private function relationTarget(array|Node|null $nodes): ?string
    {
        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                $related = $this->relationTarget($node);
                if ($related !== null) {
                    return $related;
                }
            }

            return null;
        }
        if (! $nodes instanceof Node || $nodes instanceof ClassLike || $nodes instanceof Function_) {
            return null;
        }
        if ($nodes instanceof MethodCall && $nodes->name instanceof Identifier && $this->isRelation($nodes->name->toString())
            && $nodes->var instanceof Variable && $nodes->var->name === 'this') {
            return $this->classConst($nodes->args[0]->value ?? null) ?? 'Illuminate\\Database\\Eloquent\\Relations\\Relation';
        }
        foreach ($nodes->getSubNodeNames() as $name) {
            if (is_array($nodes->$name) || $nodes->$name instanceof Node) {
                $related = $this->relationTarget($nodes->$name);
                if ($related !== null) {
                    return $related;
                }
            }
        }

        return null;
    }

    private function isRelation(string $method): bool
    {
        return in_array($method, [
            'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'morphTo', 'morphOne',
            'morphMany', 'morphToMany', 'morphedByMany', 'hasManyThrough',
            'hasOneThrough',
        ], true);
    }

    private function relate(
        string $source,
        string $target,
        string $targetType,
        string $targetSymbol,
        string $relationship,
        float $confidence,
        array $metadata = [],
    ): void {
        $this->extraction->addEdge(new ExtractedEdge(
            NodeKeys::normalize($source),
            NodeKeys::normalize($target),
            $targetType,
            $targetSymbol,
            $relationship,
            $confidence,
            $metadata,
        ));
    }
}

class SymbolExtractor
{
    public function __construct(private readonly FileHasher $hasher) {}

    /**
     * @param  list<Node\Stmt>  $statements
     */
    public function extract(FileExtraction $extraction, array $statements, string $code): void
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new PhpSymbolVisitor($extraction, $code, $this->hasher));
        $traverser->traverse($statements);
    }
}
