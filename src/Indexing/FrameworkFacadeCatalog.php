<?php

namespace ProjectMemory\Indexing;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;

/**
 * Reads installed Laravel facade accessors from framework source.
 * The result stays in memory. It is not a documentation index and it is not stored.
 */
class FrameworkFacadeCatalog
{
    /** @var array<string, array{accessor: string, kind: string, evidence: string}>|null */
    private ?array $accessors = null;

    public function __construct(
        private readonly PhpAstParser $parser,
        private readonly ?string $directory = null,
    ) {}

    /** @return array<string, array{accessor: string, kind: string, evidence: string}> */
    public function accessors(): array
    {
        if ($this->accessors !== null) {
            return $this->accessors;
        }

        $this->accessors = [];
        $directory = $this->directory ?? (function_exists('base_path')
            ? base_path('vendor/laravel/framework/src/Illuminate/Support/Facades')
            : null);
        if (! is_string($directory) || ! is_dir($directory)) {
            return $this->accessors;
        }

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $parsed = $this->parser->parse((string) file_get_contents($file));
            if ($parsed->error !== null) {
                continue;
            }
            $this->readStatements($parsed->statements, basename($file), null);
        }

        return $this->accessors;
    }

    /** @param list<Node\Stmt> $statements */
    private function readStatements(array $statements, string $evidence, ?string $namespace): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $this->readStatements($statement->stmts, $evidence, $statement->name?->toString());

                continue;
            }
            if (! $statement instanceof Class_ || $statement->name === null || ! $statement->extends instanceof Name) {
                continue;
            }
            if (! str_ends_with($statement->extends->toString(), 'Facade')) {
                continue;
            }
            $accessor = $this->accessor($statement);
            if ($accessor === null) {
                continue;
            }
            $fqcn = $namespace !== null ? $namespace.'\\'.$statement->name->toString() : $statement->name->toString();
            $this->accessors[$fqcn] = $accessor + ['evidence' => 'vendor/laravel/framework/src/Illuminate/Support/Facades/'.$evidence];
        }
    }

    /** @return array{accessor: string, kind: string}|null */
    private function accessor(Class_ $class): ?array
    {
        foreach ($class->getMethods() as $method) {
            if (! $method instanceof ClassMethod || strtolower($method->name->toString()) !== 'getfacadeaccessor') {
                continue;
            }
            foreach ($method->stmts ?? [] as $statement) {
                if (! $statement instanceof Return_) {
                    continue;
                }
                $value = $statement->expr;
                if ($value instanceof String_ && $value->value !== '') {
                    return [
                        'accessor' => $value->value,
                        'kind' => str_contains($value->value, '\\') ? 'class' : 'container_key',
                    ];
                }
                if ($value instanceof ClassConstFetch && $value->class instanceof Name && $value->name instanceof Identifier && strtolower($value->name->toString()) === 'class') {
                    return ['accessor' => $value->class->toString(), 'kind' => 'class'];
                }
            }
        }

        return null;
    }
}
