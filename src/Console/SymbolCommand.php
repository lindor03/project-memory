<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRetriever;

class SymbolCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:symbol {symbol : Class, method, file, or node key} {--budget=} {--json : Machine-readable output}';

    protected $description = 'Return symbol details and source references';

    public function handle(ContextRetriever $retriever): int
    {
        $packet = $retriever->retrieve('symbol_lookup', [
            'symbol' => (string) $this->argument('symbol'),
            'budget' => $this->budget(),
        ]);
        $missing = $packet->sources === [];

        return $this->emit($packet->toArray() + ['text' => $packet->text], $missing ? self::FAILURE : self::SUCCESS);
    }
}
