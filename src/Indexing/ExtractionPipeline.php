<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Data\FileExtraction;

class ExtractionPipeline
{
    public function __construct(
        private readonly PhpAstParser $parser,
        private readonly SymbolExtractor $symbols,
        private readonly LaravelRouteAnalyzer $routes,
        private readonly BladeAnalyzer $blade,
        private readonly MigrationAnalyzer $migrations,
        private readonly DependencyIndexer $dependencies,
        private readonly LaravelSemanticAnalyzer $laravel,
    ) {}

    public function extract(string $relativePath, string $contents, string $hash, int $bytes): FileExtraction
    {
        if (str_contains($contents, "\0")) {
            throw new \RuntimeException('Binary content was skipped.');
        }

        $extraction = new FileExtraction($relativePath, $hash, $bytes);

        if (str_ends_with($relativePath, '.blade.php')) {
            $this->blade->analyze($extraction, $contents);
            $this->dependencies->enrich($extraction);

            return $extraction;
        }

        $parsed = $this->parser->parse($contents);
        if ($parsed->error !== null) {
            throw new \RuntimeException($parsed->error);
        }

        $this->symbols->extract($extraction, $parsed->statements, $contents);
        $this->laravel->analyze($extraction, $parsed->statements);
        $this->routes->analyze($extraction, $parsed->statements, $contents);

        if (preg_match('~(?:^|/)migrations/[^/]+\.php$~', str_replace('\\', '/', $relativePath))) {
            $this->migrations->analyze($extraction, $parsed->statements);
        }

        $this->dependencies->enrich($extraction);

        return $extraction;
    }
}
