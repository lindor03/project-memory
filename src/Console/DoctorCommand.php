<?php

namespace ProjectMemory\Console;

use ProjectMemory\Indexing\PhpAstParser;
use ProjectMemory\Knowledge\KnowledgeReviewer;
use ProjectMemory\Maintenance\IndexHealth;
use ProjectMemory\Schema\SchemaInstaller;
use ProjectMemory\Support\MemoryConnection;

class DoctorCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:doctor {--json : Machine-readable output}';

    protected $description = 'Read-only connectivity, schema, parser, and live-source freshness diagnostics';

    public function handle(MemoryConnection $memory, PhpAstParser $parser, IndexHealth $health, KnowledgeReviewer $knowledge): int
    {
        $checks = [];
        $healthy = true;
        $databaseReady = false;
        try {
            $memory->assertSafe();
            $connection = app('db')->connection($memory->name());
            $connection->getPdo();
            $checks[] = ['name' => 'database', 'ok' => true, 'detail' => $memory->name()];
            $databaseReady = true;
        } catch (\Throwable $exception) {
            $healthy = false;
            $checks[] = ['name' => 'database', 'ok' => false, 'detail' => $exception->getMessage()];
        }
        foreach (SchemaInstaller::TABLES as $table) {
            try {
                $exists = $databaseReady && $connection->getSchemaBuilder()->hasTable($table);
            } catch (\Throwable) {
                $exists = false;
            }
            $checks[] = ['name' => 'table:'.$table, 'ok' => $exists];
            $healthy = $healthy && $exists;
        }
        if ($healthy) {
            $edgeProvenance = $connection->getSchemaBuilder()->hasColumn('ai_code_edges', 'declaration_path_hash');
            $checks[] = ['name' => 'edge_provenance_schema', 'ok' => $edgeProvenance];
            $healthy = $healthy && $edgeProvenance;
        }
        $parsed = $parser->parse("<?php\nclass DoctorProbe {}\n");
        $parserOk = $parsed->error === null && $parsed->statements !== [];
        $checks[] = ['name' => 'php_parser', 'ok' => $parserOk, 'detail' => $parsed->error];
        $healthy = $healthy && $parserOk;
        $index = ['initialized' => false, 'stale_files' => 0, 'last_status' => null];
        if ($healthy) {
            try {
                $index = $health->inspect();
                $checks[] = ['name' => 'index_freshness', 'ok' => $index['fresh'], 'detail' => $index['action']];
                $healthy = $healthy && $index['healthy'];
            } catch (\Throwable $exception) {
                $healthy = false;
                $checks[] = ['name' => 'index_freshness', 'ok' => false, 'detail' => $exception->getMessage()];
            }
        }
        $knowledgeReview = null;
        if ($databaseReady) {
            try {
                $knowledgeReview = $knowledge->review();
                $checks[] = [
                    'name' => 'knowledge_evidence',
                    'ok' => true,
                    'detail' => $knowledgeReview['unverified_active_or_draft'].' active or draft assertions have no source hashes. '.$knowledgeReview['action'],
                ];
            } catch (\Throwable $exception) {
                $checks[] = ['name' => 'knowledge_evidence', 'ok' => true, 'detail' => $exception->getMessage()];
            }
        }
        $payload = array_merge($index, [
            'healthy' => $healthy,
            'knowledge_review' => $knowledgeReview,
            'connection' => $memory->name(), 'database' => $memory->databaseName(),
            'mcp_write_enabled' => (bool) config('project-memory.mcp.allow_write', false),
            'checks' => $checks,
            'text' => ($healthy ? 'Project memory is healthy and fresh.' : 'Project memory needs attention. Inspect source and run ai:sync (ai:scan for an empty index).')."\n",
        ]);

        return $this->emit($payload, $healthy ? self::SUCCESS : self::FAILURE);
    }
}
