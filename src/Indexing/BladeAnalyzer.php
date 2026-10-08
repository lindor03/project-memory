<?php

namespace ProjectMemory\Indexing;

use PhpParser\Node;
use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\ExtractedNode;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\NodeKeys;

class BladeAnalyzer
{
    private readonly PhpAstParser $parser;

    public function __construct(?PhpAstParser $parser = null)
    {
        $this->parser = $parser ?? new PhpAstParser;
    }

    public function analyze(FileExtraction $extraction, string $contents): void
    {
        $view = NodeKeys::viewName($extraction->relativePath);
        $component = NodeKeys::componentName($extraction->relativePath);
        $key = $component !== null
            ? NodeKeys::normalize('component:'.$component)
            : NodeKeys::normalize('view:'.($view ?? $extraction->relativePath));
        $type = $component !== null ? 'blade_component' : 'blade_view';
        $symbol = $component ?? $view ?? $extraction->relativePath;

        $extraction->addNode(new ExtractedNode(
            $key,
            $type,
            $symbol,
            1,
            substr_count($contents, "\n") + 1,
            $extraction->contentHash,
            $type.' '.$symbol,
            ['stub' => false],
        ));

        $contents = preg_replace('/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b/s', '', $contents) ?? $contents;
        foreach ($this->directives($contents) as [$directive, $arguments]) {
            $parsed = $this->parser->parse('<?php __memory_directive('.$arguments.');');
            $call = $parsed->statements[0]->expr ?? null;
            if (! $call instanceof Node\Expr\FuncCall) {
                $extraction->notes[] = 'Blade '.$directive.' arguments could not be resolved.';

                continue;
            }
            $index = in_array($directive, ['includeWhen', 'includeUnless'], true) ? 1 : 0;
            $value = $call->args[$index]->value ?? null;
            $candidates = $value instanceof Node\Expr\Array_ ? array_map(fn ($item) => $item?->value, $value->items) : [$value];
            foreach ($candidates as $candidate) {
                if ($candidate instanceof Node\Scalar\String_) {
                    $name = $candidate->value;
                    $this->link($extraction, $key, 'view:'.$name, 'blade_view', $name, 'renders_view', ['directive' => $directive]);
                }
            }
        }

        foreach ($this->captures($contents, '/<x-([A-Za-z0-9][A-Za-z0-9._-]*)\b/') as $name) {
            if (in_array($name, ['slot', 'dynamic-component'], true)) {
                continue;
            }

            $this->link($extraction, $key, 'component:'.$name, 'blade_component', $name, 'uses_component', []);
        }

        foreach ($this->captures($contents, '/\broute\s*\(\s*[\'"]([^\'"]+)[\'"]/') as $name) {
            $this->link($extraction, $key, 'route:'.$name, 'route', $name, 'references_route', ['via' => 'route_helper']);
        }
    }

    /** @return list<array{string, string}> */
    private function directives(string $contents): array
    {
        preg_match_all('/(?<!@)@(extends|include|includeIf|includeWhen|includeUnless|includeFirst|each|component)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE);
        $directives = [];
        foreach ($matches[0] as $index => [$opening, $offset]) {
            $start = $offset + strlen($opening);
            $depth = 1;
            $quote = null;
            $length = strlen($contents);
            for ($cursor = $start; $cursor < $length; $cursor++) {
                $character = $contents[$cursor];
                if ($quote !== null) {
                    if ($character === '\\') {
                        $cursor++;
                    } elseif ($character === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($character === '"' || $character === "'") {
                    $quote = $character;
                } elseif ($character === '(') {
                    $depth++;
                } elseif ($character === ')' && --$depth === 0) {
                    $directives[] = [$matches[1][$index][0], substr($contents, $start, $cursor - $start)];
                    break;
                }
            }
        }

        return $directives;
    }

    /**
     * @return list<string>
     */
    private function captures(string $contents, string $pattern): array
    {
        preg_match_all($pattern, $contents, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function link(
        FileExtraction $extraction,
        string $source,
        string $target,
        string $type,
        string $symbol,
        string $relationship,
        array $metadata,
    ): void {
        $extraction->addEdge(new ExtractedEdge(
            $source,
            NodeKeys::normalize($target),
            $type,
            $symbol,
            $relationship,
            0.85,
            $metadata,
        ));
    }
}
