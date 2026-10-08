<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRetriever;

class OverviewCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:overview {--budget=} {--json : Machine-readable output}';

    protected $description = 'Return a compact project architecture overview from the index';

    public function handle(ContextRetriever $retriever): int
    {
        $packet = $retriever->retrieve('project_overview', ['budget' => $this->budget()]);

        return $this->emit($packet->toArray() + ['text' => $packet->text]);
    }
}
