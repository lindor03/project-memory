<?php

use Illuminate\Contracts\Console\Kernel;
use ProjectMemory\Context\ContextRetriever;
use ProjectMemory\Data\ContextPacket;
use ProjectMemory\Graph\ImpactAnalyzer;
use ProjectMemory\Indexing\IndexSynchronizer;
use ProjectMemory\Schema\SchemaInstaller;

// Isolated, repeatable synthetic workload; never touches the host's database.
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'PROJECT_MEMORY_DB_CONNECTION' => 'sqlite', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$root = getenv('PROJECT_MEMORY_APP_ROOT');
$root = is_string($root) ? $root : (getcwd() ?: '');
$guard = 0;
while ($root !== '' && $root !== dirname($root) && ! is_file($root.DIRECTORY_SEPARATOR.'artisan') && $guard < 25) {
    $root = dirname($root);
    $guard++;
}
if (! is_file($root.DIRECTORY_SEPARATOR.'artisan') || ! is_file($root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
    fwrite(STDERR, "Run the benchmark from a Laravel application that installs lindor03/project-memory, or set PROJECT_MEMORY_APP_ROOT.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
if (($argv[2] ?? null) === '--baseline') {
    $baseline = $root.'/storage/app/project-memory-audit/baseline-package/src';
    if (! is_dir($baseline)) {
        throw new RuntimeException('The original audit snapshot is not available.');
    }
    spl_autoload_register(function (string $class) use ($baseline): void {
        if (str_starts_with($class, 'ProjectMemory\\')) {
            $path = $baseline.'/'.str_replace('\\', '/', substr($class, strlen('ProjectMemory\\'))).'.php';
            if (is_file($path)) {
                require $path;
            }
        }
    }, true, true);
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = sys_get_temp_dir().'/project-memory-benchmark-'.bin2hex(random_bytes(6));
mkdir($base.'/app/Services', 0755, true);
$count = max(20, min(1000, (int) ($argv[1] ?? 100)));
for ($i = 0; $i < $count; $i++) {
    $next = ($i + 1) % $count;
    file_put_contents($base.'/app/Services/Service'.$i.'.php', "<?php\nnamespace App\\Services;\nclass Service{$i} {\n public function __construct(private Service{$next} \$next) {}\n public function run(int \$id): bool { return \$this->next->run(\$id); }\n public function label(): string { return 'label'; }\n}\n");
}
config(['project-memory.scan.base_path' => $base, 'project-memory.scan.roots' => ['app'], 'project-memory.modules' => [], 'project-memory.context.budget' => 4000]);
app(SchemaInstaller::class)->install();
$queries = 0;
app('db')->listen(function () use (&$queries) {
    $queries++;
});
$measure = function (callable $operation) use (&$queries): array {
    $queries = 0;
    $start = hrtime(true);
    $result = $operation();

    return ['ms' => round((hrtime(true) - $start) / 1e6, 3), 'queries' => $queries, 'result' => $result];
};
$sync = app(IndexSynchronizer::class);
$report = ['workload' => ['files' => $count, 'methods_per_class' => 3, 'repeats' => 5, 'database' => 'sqlite :memory:', 'php' => PHP_VERSION]];
try {
    $full = $measure(fn () => $sync->sync('full', true));
    $report['full'] = array_diff_key($full, ['result' => true]);
    $report['full']['errors'] = $full['result']->errors;
    foreach (['incremental' => fn () => $sync->sync(), 'module_context' => fn () => app(ContextRetriever::class)->retrieve('module_context', ['module' => 'app-Services']), 'focused_module_context' => fn () => app(ContextRetriever::class)->retrieve('module_context', ['module' => 'app-Services', 'query' => 'Service42']), 'symbol_lookup' => fn () => app(ContextRetriever::class)->retrieve('symbol_lookup', ['symbol' => 'App\\Services\\Service0::run']), 'impact' => fn () => app(ImpactAnalyzer::class)->analyze('App\\Services\\Service0::run')] as $name => $operation) {
        $samples = [];
        for ($trial = 0; $trial < 5; $trial++) {
            $sample = $measure($operation);
            $result = $sample['result'];
            unset($sample['result']);
            if ($result instanceof ContextPacket) {
                $sample['tokens'] = $result->estimatedTokens;
                $sample['sources'] = count($result->sources);
            }
            $samples[] = $sample;
        }
        $times = array_column($samples, 'ms');
        sort($times);
        $report[$name] = ['median_ms' => $times[2], 'queries' => array_column($samples, 'queries'), 'samples' => $samples];
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    foreach (glob($base.'/app/Services/*.php') ?: [] as $path) {
        unlink($path);
    }
    rmdir($base.'/app/Services');
    rmdir($base.'/app');
    rmdir($base);
}
