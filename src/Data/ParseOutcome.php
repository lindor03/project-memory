<?php

namespace ProjectMemory\Data;

use PhpParser\Node\Stmt;

class ParseOutcome
{
    /**
     * @param  list<Stmt>  $statements
     */
    public function __construct(
        public array $statements,
        public ?string $error,
    ) {}
}
