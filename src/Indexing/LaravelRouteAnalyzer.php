<?php

namespace ProjectMemory\Indexing;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\ExtractedNode;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\NodeKeys;

class LaravelRouteAnalyzer
{
    private const VERBS = [
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match',
        'view', 'resource', 'apiResource', 'redirect', 'permanentRedirect',
    ];

    /** @var list<array{prefix: string, name: string, controller: ?string, middleware_classes: list<string>, middleware_aliases: list<string>}> */
    private array $groups = [];

    private ?string $contents = null;

    public function __construct(private readonly FileHasher $hasher) {}

    /**
     * @param  list<Node\Stmt>  $statements
     */
    public function analyze(FileExtraction $extraction, array $statements, ?string $contents = null): void
    {
        $this->groups = [];
        $this->contents = $contents;
        $visitor = new class($this, $extraction) extends NodeVisitorAbstract
        {
            public function __construct(
                private readonly LaravelRouteAnalyzer $analyzer,
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
        if ($this->isGroup($node)) {
            $attributes = $this->arrayMap($node->args[0]->value ?? null);
            $middleware = $this->mergeMiddleware(
                $this->middlewareFrom($attributes['middleware'] ?? null),
                $this->middlewareFrom($this->chainedArgument($node, 'middleware')),
            );
            $this->groups[] = [
                'prefix' => trim($this->chainedConcat($node instanceof MethodCall ? $node->var : $node, 'prefix').'/'.$this->literal($attributes['prefix'] ?? null), '/'),
                'name' => $this->chainedConcat($node instanceof MethodCall ? $node->var : $node, 'name').$this->literal($attributes['as'] ?? null),
                'controller' => $this->classConst($this->chainedArgument($node, 'controller')) ?? $this->classConst($attributes['controller'] ?? null),
                'middleware_classes' => $middleware['classes'],
                'middleware_aliases' => $middleware['aliases'],
            ];

            return;
        }

        if (! $this->isOutermostRoute($node)) {
            return;
        }

        $this->capture($node, $extraction);
    }

    public function leave(Node $node): void
    {
        if ($this->isGroup($node)) {
            array_pop($this->groups);
        }
    }

    private function capture(Node $node, FileExtraction $extraction): void
    {
        $verbCall = $this->findVerb($node);
        if ($verbCall === null) {
            return;
        }

        $verb = $this->methodName($verbCall);
        $prefix = trim(implode('/', array_filter(array_map(fn (array $group) => $group['prefix'], $this->groups))), '/');
        $namePrefix = implode('', array_map(fn (array $group) => $group['name'], $this->groups));

        if (in_array($verb, ['resource', 'apiResource'], true)) {
            $this->captureResource($node, $verbCall, $extraction, $prefix, $namePrefix, $verb === 'apiResource');

            return;
        }

        [$uri, $action] = $this->routeArguments($verbCall, $verb);
        if ($uri === null) {
            $extraction->notes[] = 'Dynamic route URI at line '.$node->getStartLine().' was not resolved.';

            return;
        }
        $uri = trim($prefix.'/'.trim((string) $uri, '/'), '/');
        $routeName = $this->chainedConcat($node, 'name');
        $fullName = $routeName !== '' ? $namePrefix.$routeName : null;
        $key = NodeKeys::normalize($fullName !== null ? 'route:'.$fullName : 'route:'.($verb === 'match' ? implode('|', $this->matchMethods($verbCall)) : strtoupper($verb)).':'.$uri);
        $actionMeta = $this->actionMeta(in_array($verb, ['view', 'redirect', 'permanentRedirect'], true) ? null : $action);

        $extraction->addNode(new ExtractedNode(
            $key,
            'route',
            $fullName ?? strtoupper($verb).' '.$uri,
            $node->getStartLine(),
            $node->getEndLine(),
            hash('sha256', $this->routeHash($node, $extraction).'|'.$key.'|'.$uri.'|'.($actionMeta['label'] ?? '')),
            trim(strtoupper($verb).' /'.$uri.($fullName ? ' ('.$fullName.')' : '')),
            [
                'methods' => $this->httpMethods($verb, $verbCall),
                'uri' => $uri,
                'name' => $fullName,
                'action' => $actionMeta['label'],
                'confidence_note' => $actionMeta['note'],
                'stub' => false,
            ],
        ));

        $this->attachMiddleware($key, $node, $extraction);

        if ($actionMeta['class'] !== null && $actionMeta['method'] !== null) {
            $target = 'method:'.$actionMeta['class'].'::'.$actionMeta['method'];
            $extraction->addEdge(new ExtractedEdge(
                $key,
                NodeKeys::normalize($target),
                'method',
                $actionMeta['class'].'::'.$actionMeta['method'],
                'calls',
                $actionMeta['confidence'],
                ['uri' => $uri],
            ));
        }

        if ($verb === 'view') {
            $view = $this->stringArg($verbCall, 1);
            if ($view !== null) {
                $extraction->addEdge(new ExtractedEdge(
                    $key,
                    NodeKeys::normalize('view:'.$view),
                    'blade_view',
                    $view,
                    'renders_view',
                    0.9,
                ));
            }
        }
    }

    private function captureResource(
        Node $outer,
        Node $verbCall,
        FileExtraction $extraction,
        string $prefix,
        string $namePrefix,
        bool $api,
    ): void {
        $resource = $this->stringArg($verbCall, 0);
        if ($resource === null || $resource === '') {
            $extraction->notes[] = 'Dynamic resource name at line '.$outer->getStartLine().' was not resolved.';

            return;
        }
        $resource = trim($resource, '/');
        if (str_contains($resource, '/')) {
            $parts = explode('/', $resource);
            $resource = array_pop($parts);
            $prefix = trim($prefix.'/'.implode('/', $parts), '/');
        }
        $controller = $this->classConst($verbCall->args[1]->value ?? null) ?? $this->literal($verbCall->args[1]->value ?? null);
        $options = $this->arrayMap($verbCall->args[2]->value ?? null);
        $except = $this->stringList($this->chainedArgument($outer, 'except') ?? ($options['except'] ?? null));
        $onlyNode = $this->chainedArgument($outer, 'only') ?? ($options['only'] ?? null);
        $only = $onlyNode !== null ? $this->stringList($onlyNode) : null;
        $parameters = $this->arrayMap($this->chainedArgument($outer, 'parameters') ?? ($options['parameters'] ?? null));
        $names = $this->arrayMap($this->chainedArgument($outer, 'names') ?? ($options['names'] ?? null));
        $shallow = $this->hasChainedMethod($outer, 'shallow') || ($options['shallow'] ?? null) instanceof Node\Expr\ConstFetch && strtolower($options['shallow']->name->toString()) === 'true';
        $segments = explode('.', $resource);
        $base = array_pop($segments);
        $wildcard = $this->literal($parameters[$base] ?? null) ?? str_replace('-', '_', Str::singular($base));
        $resourceUri = '';
        foreach ($segments as $segment) {
            $parentParameter = $this->literal($parameters[$segment] ?? null) ?? str_replace('-', '_', Str::singular($segment));
            $resourceUri .= $segment.'/{'.$parentParameter.'}/';
        }
        $resourceUri .= $base;
        $actions = $api
            ? ['index', 'store', 'show', 'update', 'destroy']
            : ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];
        $verbs = [
            'index' => 'GET', 'create' => 'GET', 'store' => 'POST', 'show' => 'GET',
            'edit' => 'GET', 'update' => 'PUT', 'destroy' => 'DELETE',
        ];
        $uris = [
            'index' => $resourceUri,
            'create' => $resourceUri.'/create',
            'store' => $resourceUri,
            'show' => ($shallow ? $base : $resourceUri).'/{'.$wildcard.'}',
            'edit' => ($shallow ? $base : $resourceUri).'/{'.$wildcard.'}/edit',
            'update' => ($shallow ? $base : $resourceUri).'/{'.$wildcard.'}',
            'destroy' => ($shallow ? $base : $resourceUri).'/{'.$wildcard.'}',
        ];

        foreach ($actions as $action) {
            if (in_array($action, $except, true) || $only !== null && ! in_array($action, $only, true)) {
                continue;
            }

            $uri = trim($prefix.'/'.$uris[$action], '/');
            $fullName = $namePrefix.($this->literal($names[$action] ?? null) ?? (($shallow && in_array($action, ['show', 'edit', 'update', 'destroy'], true) ? $base : $resource).'.'.$action));
            $key = NodeKeys::normalize('route:'.$fullName);
            $extraction->addNode(new ExtractedNode(
                $key,
                'route',
                $fullName,
                $outer->getStartLine(),
                $outer->getEndLine(),
                hash('sha256', $this->routeHash($outer, $extraction).'|'.$key.'|'.$uri),
                $verbs[$action].' /'.$uri.' ('.$fullName.')',
                [
                    'methods' => $action === 'update' ? ['PUT', 'PATCH'] : ($verbs[$action] === 'GET' ? ['GET', 'HEAD'] : [$verbs[$action]]),
                    'uri' => $uri,
                    'name' => $fullName,
                    'action' => $controller !== null ? $controller.'@'.$action : null,
                    'synthetic' => true,
                    'stub' => false,
                ],
            ));

            $this->attachMiddleware($key, $outer, $extraction);

            if ($controller !== null) {
                $extraction->addEdge(new ExtractedEdge(
                    $key,
                    NodeKeys::normalize('method:'.$controller.'::'.$action),
                    'method',
                    $controller.'::'.$action,
                    'calls',
                    0.8,
                    ['synthetic_resource' => true, 'unresolved_options' => ['global_resource_parameters', 'global_resource_verbs']],
                ));
            }
        }
    }

    private function attachMiddleware(string $routeKey, Node $outer, FileExtraction $extraction): void
    {
        $middleware = $this->middlewareFrom($this->chainedArgument($outer, 'middleware'));
        foreach ($this->groups as $group) {
            $middleware = $this->mergeMiddleware($middleware, [
                'classes' => $group['middleware_classes'] ?? [],
                'aliases' => $group['middleware_aliases'] ?? [],
            ]);
        }
        foreach ($middleware['classes'] as $class) {
            $extraction->addEdge(new ExtractedEdge(
                $routeKey,
                NodeKeys::normalize('class:'.$class),
                'class',
                $class,
                'uses_middleware',
                0.9,
                ['resolution' => 'middleware_class', 'resolution_state' => 'resolved'],
            ));
        }
        if ($middleware['aliases'] !== [] && isset($extraction->nodes[$routeKey])) {
            $extraction->nodes[$routeKey]->metadata['middleware_aliases'] = $middleware['aliases'];
            $extraction->nodes[$routeKey]->metadata['middleware_alias_state'] = 'unresolved_alias';
        }
    }

    /** @return array{classes: list<string>, aliases: list<string>} */
    private function middlewareFrom(?Node $node): array
    {
        $classes = [];
        $aliases = [];
        $values = [];
        if ($node instanceof String_) {
            $values[] = $node->value;
        } elseif ($node instanceof ClassConstFetch) {
            $class = $this->classConst($node);
            if ($class !== null) {
                $classes[] = $class;
            }
        } elseif ($node instanceof Array_) {
            foreach ($node->items as $item) {
                if ($item?->value instanceof String_) {
                    $values[] = $item->value->value;
                } elseif ($item?->value instanceof ClassConstFetch && ($class = $this->classConst($item->value)) !== null) {
                    $classes[] = $class;
                }
            }
        }
        foreach ($values as $value) {
            if (str_contains($value, '\\')) {
                $classes[] = ltrim($value, '\\');
            } else {
                $aliases[] = $value;
            }
        }

        return ['classes' => array_values(array_unique($classes)), 'aliases' => array_values(array_unique($aliases))];
    }

    /**
     * @param  array{classes: list<string>, aliases: list<string>}  $left
     * @param  array{classes: list<string>, aliases: list<string>}  $right
     * @return array{classes: list<string>, aliases: list<string>}
     */
    private function mergeMiddleware(array $left, array $right): array
    {
        return [
            'classes' => array_values(array_unique([...$left['classes'], ...$right['classes']])),
            'aliases' => array_values(array_unique([...$left['aliases'], ...$right['aliases']])),
        ];
    }

    private function isGroup(Node $node): bool
    {
        return $this->methodName($node) === 'group' && $this->reachesRoute($node);
    }

    private function isOutermostRoute(Node $node): bool
    {
        if ($this->findVerb($node) === null || ! $this->reachesRoute($node)) {
            return false;
        }

        $parent = $node->getAttribute('parent');

        return ! ($parent instanceof MethodCall && $parent->var === $node);
    }

    private function findVerb(Node $node): ?Node
    {
        $cursor = $node;

        while ($cursor instanceof MethodCall || $cursor instanceof StaticCall) {
            if (in_array($this->methodName($cursor), self::VERBS, true)) {
                return $cursor;
            }

            if (! $cursor instanceof MethodCall) {
                break;
            }

            $cursor = $cursor->var;
        }

        return null;
    }

    private function reachesRoute(Node $node): bool
    {
        $cursor = $node;

        while ($cursor instanceof MethodCall || $cursor instanceof StaticCall) {
            if ($cursor instanceof StaticCall && $this->isRoute($cursor->class)) {
                return true;
            }

            if (! $cursor instanceof MethodCall) {
                break;
            }

            $cursor = $cursor->var;
        }

        return false;
    }

    private function isRoute(Node $class): bool
    {
        $name = $class instanceof Name ? $class->toString() : '';

        return $name === 'Illuminate\\Support\\Facades\\Route' || $name === 'Route';
    }

    private function methodName(Node $node): ?string
    {
        if (($node instanceof MethodCall || $node instanceof StaticCall) && $node->name instanceof Identifier) {
            return $node->name->toString();
        }

        return null;
    }

    private function chainedConcat(Node $start, string $method): string
    {
        $calls = [];
        $cursor = $start;

        while ($cursor instanceof MethodCall || $cursor instanceof StaticCall) {
            $calls[] = $cursor;
            $cursor = $cursor instanceof MethodCall ? $cursor->var : null;
        }

        $value = '';
        foreach (array_reverse($calls) as $call) {
            if ($this->methodName($call) === $method) {
                $value .= $this->stringArg($call, 0) ?? '';
            }
        }

        return $value;
    }

    /**
     * @return array{0: ?string, 1: ?Node}
     */
    private function routeArguments(Node $verbCall, string $verb): array
    {
        if ($verb === 'match') {
            return [$this->stringArg($verbCall, 1), $verbCall->args[2]->value ?? null];
        }

        return [$this->stringArg($verbCall, 0), $verbCall->args[1]->value ?? null];
    }

    /**
     * @return list<string>
     */
    private function matchMethods(Node $verbCall): array
    {
        $value = $verbCall->args[0]->value ?? null;

        return array_map('strtoupper', $this->stringList($value));
    }

    /**
     * @return list<string>
     */
    private function exceptList(Node $outer): array
    {
        $cursor = $outer;

        while ($cursor instanceof MethodCall || $cursor instanceof StaticCall) {
            if ($this->methodName($cursor) === 'except') {
                return $this->stringList($cursor->args[0]->value ?? null);
            }

            if (! $cursor instanceof MethodCall) {
                break;
            }

            $cursor = $cursor->var;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function stringList(?Node $node): array
    {
        if ($node instanceof String_) {
            return [$node->value];
        }

        if (! $node instanceof Array_) {
            return [];
        }

        $values = [];
        foreach ($node->items as $item) {
            if ($item?->value instanceof String_) {
                $values[] = $item->value->value;
            }
        }

        return $values;
    }

    private function stringArg(Node $node, int $index): ?string
    {
        $value = $node->args[$index]->value ?? null;

        return $value instanceof String_ ? $value->value : null;
    }

    private function routeHash(Node $node, FileExtraction $extraction): string
    {
        return $this->contents !== null
            ? $this->hasher->lines($this->contents, $node->getStartLine(), $node->getEndLine())
            : $extraction->contentHash;
    }

    /** @return list<string> */
    private function httpMethods(string $verb, Node $call): array
    {
        return match ($verb) {
            'get', 'view' => ['GET', 'HEAD'],
            'any', 'redirect', 'permanentRedirect' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'match' => $this->matchMethods($call),
            default => [strtoupper($verb)],
        };
    }

    private function literal(?Node $node): ?string
    {
        return $node instanceof String_ ? $node->value : null;
    }

    /** @return array<string, Node> */
    private function arrayMap(?Node $node): array
    {
        $values = [];
        if ($node instanceof Array_) {
            foreach ($node->items as $item) {
                if ($item?->key instanceof String_) {
                    $values[$item->key->value] = $item->value;
                }
            }
        }

        return $values;
    }

    private function chainedArgument(Node $outer, string $method): ?Node
    {
        $cursor = $outer;
        while ($cursor instanceof MethodCall || $cursor instanceof StaticCall) {
            if ($this->methodName($cursor) === $method) {
                return $cursor->args[0]->value ?? null;
            }
            $cursor = $cursor instanceof MethodCall ? $cursor->var : null;
        }

        return null;
    }

    private function hasChainedMethod(Node $outer, string $method): bool
    {
        $cursor = $outer;
        while ($cursor instanceof MethodCall || $cursor instanceof StaticCall) {
            if ($this->methodName($cursor) === $method) {
                return true;
            }
            $cursor = $cursor instanceof MethodCall ? $cursor->var : null;
        }

        return false;
    }

    /**
     * @return array{label: ?string, class: ?string, method: ?string, confidence: float, note: ?string}
     */
    private function actionMeta(?Node $action): array
    {
        $empty = ['label' => null, 'class' => null, 'method' => null, 'confidence' => 0.5, 'note' => null];

        if ($action instanceof Array_) {
            $class = $this->classConst($action->items[0]->value ?? null);
            $method = ($action->items[1]->value ?? null) instanceof String_ ? $action->items[1]->value->value : null;

            return [
                'label' => $class !== null && $method !== null ? $class.'@'.$method : null,
                'class' => $class,
                'method' => $method,
                'confidence' => 0.9,
                'note' => null,
            ];
        }

        $invokable = $this->classConst($action);
        if ($invokable !== null) {
            return ['label' => $invokable.'@__invoke', 'class' => $invokable, 'method' => '__invoke', 'confidence' => 0.9, 'note' => null];
        }

        if ($action instanceof String_ && ! str_contains($action->value, '@')) {
            foreach (array_reverse($this->groups) as $group) {
                if (($group['controller'] ?? null) !== null) {
                    return ['label' => $group['controller'].'@'.$action->value, 'class' => $group['controller'], 'method' => $action->value, 'confidence' => 0.9, 'note' => null];
                }
            }
        }

        if ($action instanceof String_ && str_contains($action->value, '@')) {
            [$class, $method] = explode('@', $action->value, 2);

            return [
                'label' => $action->value,
                'class' => $class,
                'method' => $method,
                'confidence' => 0.75,
                'note' => 'String controller actions are not namespace-resolved.',
            ];
        }

        if ($action instanceof Closure || $action instanceof Node\Expr\ArrowFunction) {
            return [
                'label' => 'closure',
                'class' => null,
                'method' => null,
                'confidence' => 0.55,
                'note' => 'Closure actions are not expanded into symbol calls.',
            ];
        }

        return $empty;
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
}
