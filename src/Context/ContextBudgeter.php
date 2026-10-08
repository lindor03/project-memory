<?php

namespace ProjectMemory\Context;

use ProjectMemory\Data\ContextItem;

class ContextBudgeter
{
    public function __construct(private readonly TokenEstimator $estimator) {}

    /**
     * @param  list<ContextItem>  $items
     * @return array{text: string, estimated_tokens: int, truncated: bool, sources: list<array<string, mixed>>, omitted_count: int, compacted_count: int}
     */
    public function constrain(string $header, array $items, int $budget): array
    {
        $budget = max(0, $budget);
        $maxBytes = $budget * $this->estimator->charsPerToken();
        $text = $this->estimator->truncate($header, $maxBytes);
        $sources = [];
        $truncated = $text !== $header;
        $omitted = 0;
        $compacted = 0;

        foreach ($items as $item) {
            $separator = $text !== '' ? "\n" : '';
            $selected = $item->text;
            $compact = false;
            if (strlen($text.$separator.$selected) > $maxBytes && $item->compactText !== null) {
                $selected = $item->compactText;
                $compact = true;
            }
            if (strlen($text.$separator.$selected) > $maxBytes || $selected === '') {
                $truncated = true;
                $omitted++;

                continue;
            }

            $text .= $separator.$selected;
            $source = $item->source;
            if ($compact && array_key_exists('summary', $source)) {
                $source['summary'] = null;
            }
            $sources[] = $source + ['compacted' => $compact, 'relevance_score' => $item->score];
            if ($compact) {
                $compacted++;
                $truncated = true;
            }
        }

        return [
            'text' => $text,
            'estimated_tokens' => $this->estimator->estimate($text),
            'truncated' => $truncated,
            'sources' => $sources,
            'omitted_count' => $omitted,
            'compacted_count' => $compacted,
        ];
    }
}
