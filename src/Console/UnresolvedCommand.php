<?php

namespace ProjectMemory\Console;

use ProjectMemory\Indexing\UnresolvedReferenceClassifier;

class UnresolvedCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:unresolved {--json : Machine-readable output}';

    protected $description = 'Classify unresolved call references without treating the counts as accuracy';

    public function handle(UnresolvedReferenceClassifier $classifier): int
    {
        $report = $classifier->report();

        return $this->emit($report + [
            'text' => 'Call edges '.$report['call_edges'].'. Dynamic untyped call sites '.$report['dynamic_untyped_call_sites'].".\n".$report['note']."\n",
        ]);
    }
}
