<?php

namespace ProjectMemory\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Boost\BoostServiceProvider;
use ProjectMemory\Context\ContextRouter;
use ProjectMemory\Indexing\IndexSynchronizer;
use ProjectMemory\Integration\CursorConfiguration;
use ProjectMemory\Mcp\McpServer;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\PackageInfo;
use ProjectMemory\ProjectMemoryServiceProvider;
use ProjectMemory\Schema\SchemaInstaller;
use ProjectMemory\Support\MemoryConnection;

class PortabilityTest extends TestCase
{
    public function test_package_discovers_provider_commands_and_sqlite_default(): void
    {
        $providers = array_keys(Artisan::all());

        $this->assertContains('project-memory:install', $providers);
        $this->assertContains('ai:scan', $providers);
        $this->assertContains('ai:sync', $providers);
        $this->assertContains('ai:doctor', $providers);
        $this->assertContains('ai:mcp', $providers);
        $this->assertSame('sqlite', app(MemoryConnection::class)->name());
        $this->assertSame(':memory:', app(MemoryConnection::class)->databaseName());
        $this->assertSame([], config('project-memory.evaluation.scenarios'));
        $this->assertSame([], config('project-memory.database.blocked_databases'));
        $this->assertFalse(class_exists(BoostServiceProvider::class));

        $registered = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-memory-register-'.uniqid().'.sqlite';
        config([
            'project-memory.database.connection' => 'project_memory_registered',
            'project-memory.database.driver' => 'sqlite',
            'project-memory.database.database' => $registered,
        ]);
        (new ProjectMemoryServiceProvider(app()))->register();
        $this->assertSame('sqlite', config('database.connections.project_memory_registered.driver'));
        $this->assertSame($registered, config('database.connections.project_memory_registered.database'));
    }

    public function test_install_is_repeatable_and_preserves_knowledge(): void
    {
        $this->artisan('project-memory:install')->assertSuccessful();

        AiKnowledge::query()->create([
            'knowledge_key' => 'portable-rule',
            'kind' => 'decision',
            'title' => 'Keep knowledge',
            'body' => 'Upgrades must not delete this assertion.',
            'state' => AiKnowledge::STATE_ACTIVE,
            'source_hashes' => ['abc'],
            'metadata' => ['version' => 1],
        ]);

        $this->artisan('project-memory:install', ['--migrate' => true])
            ->assertSuccessful();
        app(SchemaInstaller::class)->install();

        $this->assertTrue(app(SchemaInstaller::class)->ready());
        $this->assertSame(
            'Upgrades must not delete this assertion.',
            AiKnowledge::query()->where('knowledge_key', 'portable-rule')->value('body')
        );
        $this->assertSame(['abc'], AiKnowledge::query()->where('knowledge_key', 'portable-rule')->first()->source_hashes);
    }

    public function test_memory_sqlite_stays_separate_from_the_application_database(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-memory-isolation-'.uniqid();
        mkdir($directory);
        $application = $directory.DIRECTORY_SEPARATOR.'application.sqlite';
        $memory = $directory.DIRECTORY_SEPARATOR.'memory.sqlite';
        touch($application);

        config([
            'database.default' => 'application',
            'database.connections.application' => [
                'driver' => 'sqlite',
                'database' => $application,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.connections.project_memory' => [
                'driver' => 'sqlite',
                'database' => $memory,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'project-memory.database.connection' => 'project_memory',
            'project-memory.database.driver' => 'sqlite',
            'project-memory.database.database' => $memory,
        ]);
        app('db')->purge('project_memory');
        app()->forgetInstance(MemoryConnection::class);

        $this->assertSame($memory, app(MemoryConnection::class)->databaseName());
        $this->artisan('project-memory:install')->assertSuccessful();

        $this->assertTrue(Schema::connection('project_memory')->hasTable('ai_knowledge'));
        $this->assertFalse(Schema::connection('application')->hasTable('ai_knowledge'));
        $this->assertFileExists($memory);

        foreach (['project_memory', 'application'] as $connection) {
            app('db')->disconnect($connection);
        }
        $this->deleteTree($directory);
    }

    public function test_index_sync_and_mcp_initialize_without_boost(): void
    {
        $fixture = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-memory-fixture-'.uniqid();
        mkdir($fixture.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services', 0755, true);
        file_put_contents($fixture.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'OrderService.php', <<<'PHP'
<?php

namespace App\Services;

class OrderService
{
    public function update(int $id): bool
    {
        return $id > 0;
    }
}
PHP
        );
        config([
            'project-memory.scan.base_path' => $fixture,
            'project-memory.scan.roots' => ['app'],
            'project-memory.modules' => [
                'orders' => ['name' => 'Orders', 'paths' => ['app/Services/OrderService.php']],
            ],
        ]);

        app(SchemaInstaller::class)->install();
        $full = app(IndexSynchronizer::class)->sync('full');
        $incremental = app(IndexSynchronizer::class)->sync('incremental');

        $this->assertSame('completed', $full->status);
        $this->assertSame([], $full->errors);
        $this->assertGreaterThan(0, $full->files_updated);
        $this->assertSame(0, $incremental->files_updated);
        $this->assertGreaterThan(0, $incremental->files_skipped);
        $this->assertNotNull(AiCodeNode::query()->where('symbol_name', 'App\\Services\\OrderService::update')->first());

        $response = app(McpServer::class)->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'test', 'version' => '0']],
        ]);
        $this->assertSame(PackageInfo::VERSION, $response['result']['serverInfo']['version']);
        $listed = app(McpServer::class)->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);
        $names = array_column($listed['result']['tools'], 'name');
        $this->assertContains('symbol_lookup', $names);
        $this->assertContains('impact_analysis', $names);
        $this->assertNotContains('search-docs', $names);

        $route = (new ContextRouter)->route('How does Laravel validation work?');
        $this->assertSame(['laravel_boost'], $route['sources']);
        $this->assertFalse($route['boost_installed']);

        $this->deleteTree($fixture);
    }

    public function test_cursor_merge_preserves_existing_servers_and_rules(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'project-memory-cursor-'.uniqid();
        mkdir($root.DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'rules', 0755, true);
        $mcp = $root.DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'mcp.json';
        file_put_contents($mcp, json_encode([
            'mcpServers' => [
                'laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']],
            ],
        ]));
        $rule = $root.DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'rules'.DIRECTORY_SEPARATOR.'project-memory.mdc';
        file_put_contents($rule, 'host rule');

        $cursor = new CursorConfiguration;
        $added = $cursor->mergeMcpServer($root);
        $this->assertSame('added', $added['status']);
        $decoded = json_decode((string) file_get_contents($mcp), true);
        $this->assertSame(['artisan', 'boost:mcp'], $decoded['mcpServers']['laravel-boost']['args']);
        $this->assertSame(['artisan', 'ai:mcp'], $decoded['mcpServers']['project-memory']['args']);
        $this->assertSame('php', $decoded['mcpServers']['project-memory']['command']);

        $again = $cursor->mergeMcpServer($root);
        $this->assertSame('unchanged_existing', $again['status']);
        $this->assertSame('unchanged_existing', $cursor->publishRule($root)['status']);
        $this->assertSame('host rule', file_get_contents($rule));

        $this->deleteTree($root);
    }

    public function test_distributable_files_omit_host_specific_locations(): void
    {
        $root = dirname(__DIR__);
        $needles = ['starter_app', 'clinic_admin', 'laragon', 'laravel_starter'];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/.git/') || str_contains($path, '/.phpunit.cache/')) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $contents, $path.' contains '.$needle);
            }
        }
    }

    private function deleteTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
