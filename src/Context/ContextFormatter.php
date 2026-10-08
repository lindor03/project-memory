<?php

namespace ProjectMemory\Context;

class ContextFormatter
{
    /**
     * @param  list<string>  $warnings
     */
    public function header(string $title, array $warnings = []): string
    {
        $lines = [$title, 'Memory is advisory; live source is authoritative. Token estimates use UTF-8 bytes/'.max(1, (int) config('project-memory.context.chars_per_token', 4)).' for context text.'];
        foreach ($warnings as $warning) {
            $lines[] = 'Warning: '.$warning;
        }

        return implode("\n", $lines);
    }
}
