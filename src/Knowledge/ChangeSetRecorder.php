<?php

namespace ProjectMemory\Knowledge;

use ProjectMemory\Models\AiChangeSet;

class ChangeSetRecorder
{
    /**
     * @param  list<string>  $files
     * @param  list<string>  $symbols
     */
    public function record(
        string $intent,
        array $files = [],
        array $symbols = [],
        ?string $commit = null,
        ?string $expected = null,
        ?string $actual = null,
        ?string $validation = null,
        ?string $lessons = null,
    ): AiChangeSet {
        return AiChangeSet::query()->create([
            'intent' => $intent,
            'git_commit' => $commit,
            'changed_files' => $files,
            'changed_symbols' => $symbols,
            'expected_impact' => $expected,
            'actual_impact' => $actual,
            'validation_outcome' => $validation,
            'lessons' => $lessons,
        ]);
    }
}
