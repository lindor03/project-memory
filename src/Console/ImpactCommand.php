<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRetriever;

class ImpactCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:impact {target : File, class, method, route, or module} {--depth=} {--max-nodes=} {--budget=} {--json : Machine-readable output}';

    protected $description = 'Show known callers and dependencies for a symbol';

    public function handle(ContextRetriever $retriever): int
    {
        $packet = $retriever->retrieve('impact_analysis', [
            'target' => (string) $this->argument('target'),
            'depth' => $this->option('depth'),
            'max_nodes' => $this->option('max-nodes'),
            'budget' => $this->budget(),
        ]);
        $missing = $packet->sources === [];

        return $this->emit($packet->toArray() + ['text' => $packet->text], $missing ? self::FAILURE : self::SUCCESS);
    }
}
