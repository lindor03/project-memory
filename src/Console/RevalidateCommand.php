<?php

namespace ProjectMemory\Console;

use ProjectMemory\Knowledge\KnowledgeReviewer;

class RevalidateCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:revalidate {--mark-unverified-stale : Mark active or draft knowledge that has no source hashes as stale} {--json : Machine-readable output}';

    protected $description = 'Review knowledge evidence without promoting any assertion';

    public function handle(KnowledgeReviewer $reviewer): int
    {
        $marked = $this->option('mark-unverified-stale') ? $reviewer->markUnverifiedStale() : 0;
        $review = $reviewer->review();

        return $this->emit($review + [
            'marked_stale' => $marked,
            'text' => 'Unverified active or draft knowledge: '.$review['unverified_active_or_draft'].'. Marked stale this run: '.$marked.".\n".$review['action']."\n",
        ]);
    }
}
