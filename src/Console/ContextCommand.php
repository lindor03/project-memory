<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRetriever;

class ContextCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:context {module : Module key or name} {--query= : Focus lexical and graph retrieval on this task} {--budget=} {--include-stale : Include stale knowledge} {--json : Machine-readable output}';

    protected $description = 'Return compact context for one module';

    public function handle(ContextRetriever $retriever): int
    {
        $packet = $retriever->retrieve('module_context', [
            'module' => (string) $this->argument('module'),
            'budget' => $this->budget(),
            'query' => (string) $this->option('query'),
            'include_stale' => (bool) $this->option('include-stale'),
        ]);
        $code = $packet->warnings !== [] && $packet->sources === [] ? self::FAILURE : self::SUCCESS;

        return $this->emit($packet->toArray() + ['text' => $packet->text], $code);
    }
}
