<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRetriever;

class RulesCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:rules {--include-stale : Include stale knowledge} {--budget=} {--json : Machine-readable output}';

    protected $description = 'List explicitly approved architectural rules';

    public function handle(ContextRetriever $retriever): int
    {
        $packet = $retriever->retrieve('architecture_rules', [
            'budget' => $this->budget(),
            'include_stale' => (bool) $this->option('include-stale'),
        ]);

        return $this->emit($packet->toArray() + ['text' => $packet->text]);
    }
}
