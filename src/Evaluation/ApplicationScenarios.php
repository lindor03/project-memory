<?php

namespace ProjectMemory\Evaluation;

/** Retrieval expectations supplied by the host application. The package ships none. */
class ApplicationScenarios
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $configured = config('project-memory.evaluation.scenarios', []);

        return is_array($configured) ? array_values($configured) : [];
    }
}
