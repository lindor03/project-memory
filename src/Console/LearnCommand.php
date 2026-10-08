<?php

namespace ProjectMemory\Console;

use ProjectMemory\Knowledge\ChangeSetRecorder;
use ProjectMemory\Knowledge\KnowledgeStore;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Schema\SchemaInstaller;

class LearnCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:learn
        {--intent= : Change intent to record}
        {--title= : Knowledge title}
        {--body= : Knowledge body}
        {--kind=decision : architectural_rule, module_summary, convention, decision, pattern, or lesson}
        {--revise= : Preserve and supersede this existing knowledge key}
        {--verified-by=cli : Identity explicitly approving this knowledge}
        {--approve : Mark knowledge active after explicit approval}
        {--reject : Store the knowledge as rejected}
        {--archive : Store the knowledge as archived}
        {--symbols= : Comma-separated symbols or node keys}
        {--files= : Comma-separated repository paths}
        {--validation= : Validation outcome for the change set}
        {--lessons= : Lesson learned}
        {--commit= : Git commit hash}
        {--expected= : Expected impact}
        {--actual= : Actual impact}
        {--json : Machine-readable output}';

    protected $description = 'Record a change set or explicitly approved project knowledge';

    public function handle(SchemaInstaller $schema, KnowledgeStore $knowledge, ChangeSetRecorder $changes): int
    {
        $schema->install();
        $flags = array_filter([
            'approve' => $this->option('approve'),
            'reject' => $this->option('reject'),
            'archive' => $this->option('archive'),
        ]);

        if (count($flags) > 1) {
            return $this->emit(['text' => "Choose only one of --approve, --reject, or --archive.\n", 'error' => 'conflicting_state'], self::FAILURE);
        }

        $symbols = $this->csv('symbols');
        $files = $this->csv('files');
        $recorded = [];

        if ($this->option('title') || $this->option('body')) {
            if (! $this->option('title') || ! $this->option('body')) {
                return $this->emit(['text' => "Knowledge requires both --title and --body.\n", 'error' => 'incomplete_knowledge'], self::FAILURE);
            }

            $state = match (true) {
                (bool) $this->option('approve') => AiKnowledge::STATE_ACTIVE,
                (bool) $this->option('reject') => AiKnowledge::STATE_REJECTED,
                (bool) $this->option('archive') => AiKnowledge::STATE_ARCHIVED,
                default => AiKnowledge::STATE_DRAFT,
            };

            try {
                $arguments = [
                    (string) $this->option('title'),
                    (string) $this->option('body'),
                    (string) $this->option('kind'),
                    $state,
                    $symbols,
                    $files,
                    (string) $this->option('verified-by'),
                ];
                $row = $this->option('revise')
                    ? $knowledge->revise((string) $this->option('revise'), ...$arguments)
                    : $knowledge->record(...$arguments);
            } catch (\InvalidArgumentException $exception) {
                return $this->emit(['text' => $exception->getMessage()."\n", 'error' => $exception->getMessage()], self::FAILURE);
            }

            $recorded['knowledge'] = $row->only(['knowledge_key', 'state', 'kind', 'title', 'version', 'confidence']);
        }

        if ($this->option('intent')) {
            $change = $changes->record(
                (string) $this->option('intent'),
                $files,
                $symbols,
                $this->option('commit') ? (string) $this->option('commit') : null,
                $this->option('expected') ? (string) $this->option('expected') : null,
                $this->option('actual') ? (string) $this->option('actual') : null,
                $this->option('validation') ? (string) $this->option('validation') : null,
                $this->option('lessons') ? (string) $this->option('lessons') : null,
            );
            $recorded['change_set'] = $change->only(['id', 'intent', 'validation_outcome']);
        }

        if ($recorded === []) {
            return $this->emit([
                'text' => "Provide --intent and/or both --title and --body. Approval is never implied.\n",
                'error' => 'nothing_to_record',
            ], self::FAILURE);
        }

        return $this->emit([
            'text' => 'Recorded project memory. Suggestions stay draft until --approve.'."\n",
            'recorded' => $recorded,
        ]);
    }

    /**
     * @return list<string>
     */
    private function csv(string $option): array
    {
        $value = (string) $this->option($option);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
