<?php

namespace ProjectMemory\Context;

use Laravel\Boost\BoostServiceProvider;

/**
 * Chooses which evidence source a task needs. It does not call documentation search
 * and it does not copy either tool's payload into the other.
 */
class ContextRouter
{
    /** @return array<string, mixed> */
    public function route(string $task): array
    {
        $task = trim($task);
        $framework = preg_match('/\b(laravel|eloquent|artisan|fortify|sanctum|middleware|validation|service container|form request|migration|queue|notification|facade)\b/i', $task) === 1;
        $documentation = preg_match('/\b(how do i|how does|what is|documentation|docs|convention|best practice|official)\b/i', $task) === 1;
        $symbol = preg_match('/\\\\|::/', $task) === 1;
        $application = $symbol
            || preg_match('/(?:^|\s)(?:app|routes|resources|database)\//', $task) === 1
            || (preg_match('/\b(update|modify|change|refactor|add|fix)\b/i', $task) === 1 && preg_match('/\b[A-Z][A-Za-z0-9_]+/', $task) === 1);
        $inspectSource = $symbol || preg_match('/\b(read|inspect|open)\b.+\b(source|file)\b/i', $task) === 1;

        $sources = [];
        if ($application || (! $documentation && ! $framework)) {
            $sources[] = 'project_memory';
        }
        if ($framework && ($documentation || $application)) {
            $sources[] = 'laravel_boost';
        }
        if ($inspectSource || $application) {
            $sources[] = 'source_inspection';
        }
        if ($sources === []) {
            $sources[] = 'source_inspection';
        }

        $projectTools = [];
        if (in_array('project_memory', $sources, true)) {
            $projectTools[] = 'index_status';
            if (preg_match('/\b(impact|callers|used by|dependenc)/i', $task) === 1) {
                $projectTools[] = 'impact_analysis';
            }
            $projectTools[] = $symbol ? 'symbol_lookup' : 'module_context';
            if ($symbol && ! in_array('impact_analysis', $projectTools, true)) {
                $projectTools[] = 'impact_analysis';
            }
        }

        return [
            'sources' => array_values(array_unique($sources)),
            'project_memory_tools' => $projectTools,
            'laravel_boost_tools' => in_array('laravel_boost', $sources, true) ? ['search-docs'] : [],
            'boost_query_limit' => in_array('laravel_boost', $sources, true) ? 1000 : 0,
            'inspect_source_before_edit' => in_array('source_inspection', $sources, true),
            'reason' => $this->reason($application, $framework, $documentation),
            'deduplicate' => true,
            'documentation_storage' => 'laravel_boost_only',
            'boost_installed' => class_exists(BoostServiceProvider::class),
        ];
    }

    private function reason(bool $application, bool $framework, bool $documentation): string
    {
        if ($application && $framework) {
            return 'Use project memory for this application, then a narrow Laravel Boost search only for the framework question. Inspect cited source before editing.';
        }
        if ($framework && $documentation) {
            return 'This is framework documentation. Use Laravel Boost search-docs with a narrow query. Project memory does not store Laravel documentation.';
        }
        if ($application) {
            return 'This is application work. Retrieve focused project memory and inspect cited source. Skip framework documentation unless a framework call is uncertain.';
        }

        return 'Inspect the cited source. Retrieve project memory only when an application symbol or module is involved.';
    }
}
