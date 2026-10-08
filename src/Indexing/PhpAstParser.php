<?php

namespace ProjectMemory\Indexing;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ProjectMemory\Data\ParseOutcome;

class PhpAstParser
{
    private readonly Parser $parser;

    public function __construct()
    {
        // Repository syntax may be newer than the PHP binary running the indexer.
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function parse(string $code): ParseOutcome
    {
        try {
            $statements = $this->parser->parse($code) ?? [];
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor(new ParentConnectingVisitor);
            $statements = $traverser->traverse($statements);

            return new ParseOutcome($statements, null);
        } catch (\Throwable $exception) {
            return new ParseOutcome([], $exception->getMessage());
        }
    }
}
