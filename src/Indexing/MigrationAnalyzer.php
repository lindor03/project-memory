<?php

namespace ProjectMemory\Indexing;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\ExtractedNode;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\NodeKeys;

class MigrationAnalyzer
{
    public function __construct(private readonly FileHasher $hasher) {}

    /**
     * @param  list<Node\Stmt>  $statements
     */
    public function analyze(FileExtraction $extraction, array $statements): void
    {
        $migrationKey = NodeKeys::normalize('migration:'.$extraction->relativePath);
        $extraction->addNode(new ExtractedNode(
            $migrationKey,
            'migration',
            $extraction->relativePath,
            1,
            null,
            $extraction->contentHash,
            'migration '.$extraction->relativePath,
            ['stub' => false],
        ));

        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($statements, StaticCall::class) as $call) {
            if (! $call->class instanceof Name || ! in_array($call->class->toString(), ['Illuminate\\Support\\Facades\\Schema', 'Schema'], true)) {
                continue;
            }

            // Rollback declarations describe reverse operations, not the schema
            // installed by this migration.
            $parent = $call->getAttribute('parent');
            while ($parent instanceof Node && ! $parent instanceof Node\Stmt\ClassMethod) {
                $parent = $parent->getAttribute('parent');
            }
            if ($parent instanceof Node\Stmt\ClassMethod && strtolower($parent->name->toString()) !== 'up') {
                continue;
            }

            if (! $call->name instanceof Identifier) {
                continue;
            }

            $operation = $call->name->toString();
            if (! in_array($operation, ['create', 'table', 'drop', 'dropIfExists'], true)) {
                continue;
            }

            $table = ($call->args[0]->value ?? null) instanceof String_ ? $call->args[0]->value->value : null;
            if ($table === null || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
                continue;
            }

            $columns = [];
            $closure = $call->args[1]->value ?? null;
            if ($closure instanceof Closure) {
                $columns = $this->columns($closure, $extraction->relativePath);
            }

            $tableKey = NodeKeys::normalize('table:'.$table);
            $existing = $extraction->nodes[$tableKey] ?? null;
            $mergedColumns = array_values(array_column(array_merge($existing?->metadata['columns'] ?? [], $columns), null, 'name'));
            $extraction->addNode(new ExtractedNode(
                $tableKey,
                'table',
                $table,
                $call->getStartLine(),
                $call->getEndLine(),
                hash('sha256', $extraction->relativePath.'|'.$operation.'|'.json_encode($mergedColumns)),
                'table '.$table,
                [
                    'shared' => true,
                    'stub' => false,
                    'source' => $extraction->relativePath,
                    'columns' => $mergedColumns,
                    'operation' => $operation,
                    'schema_scope' => 'migration_up_declarations',
                ],
                true,
            ));
            $extraction->addEdge(new ExtractedEdge(
                $migrationKey,
                $tableKey,
                'table',
                $table,
                'defines_table',
                0.9,
                ['operation' => $operation],
            ));
        }
    }

    /**
     * @return list<array{name: string, type: string, source: string}>
     */
    private function columns(Closure $closure, string $source): array
    {
        $columns = [];
        $finder = new NodeFinder;
        $parameter = $closure->params[0]->var ?? null;
        $receiver = $parameter instanceof Variable ? $parameter->name : null;
        $types = [
            'id', 'increments', 'tinyIncrements', 'smallIncrements', 'mediumIncrements', 'bigIncrements',
            'char', 'string', 'tinyText', 'text', 'mediumText', 'longText', 'integer', 'tinyInteger', 'smallInteger',
            'mediumInteger', 'bigInteger', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger',
            'unsignedMediumInteger', 'unsignedBigInteger', 'float', 'double', 'decimal', 'unsignedDecimal',
            'boolean', 'enum', 'set', 'json', 'jsonb', 'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz',
            'timestamp', 'timestampTz', 'year', 'binary', 'uuid', 'ulid', 'ipAddress', 'macAddress',
            'foreignId', 'foreignUuid', 'foreignUlid', 'geometry', 'geography',
        ];

        foreach ($finder->findInstanceOf($closure, MethodCall::class) as $call) {
            if (! $call->var instanceof Variable || $call->var->name !== $receiver || ! $call->name instanceof Identifier) {
                continue;
            }

            $method = $call->name->toString();
            $value = $call->args[0]->value ?? null;
            $name = $value instanceof String_ ? $value->value : match ($method) {
                'id' => 'id', 'uuid' => 'uuid', 'ulid' => 'ulid',
                'softDeletes', 'softDeletesTz', 'softDeletesDatetime' => 'deleted_at',
                'rememberToken' => 'remember_token',
                default => null,
            };
            if (in_array($method, ['timestamps', 'timestampsTz'], true)) {
                foreach (['created_at', 'updated_at'] as $timestamp) {
                    $columns[$timestamp] = ['name' => $timestamp, 'type' => $method === 'timestampsTz' ? 'timestampTz' : 'timestamp', 'source' => $source];
                }

                continue;
            }
            if (! in_array($method, array_merge($types, ['softDeletes', 'softDeletesTz', 'softDeletesDatetime', 'rememberToken']), true)
                || $name === null || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }

            $columns[$name] = [
                'name' => $name,
                'type' => $method,
                'source' => $source,
            ];
        }

        return array_values($columns);
    }
}
