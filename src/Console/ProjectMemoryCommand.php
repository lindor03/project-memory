<?php

namespace ProjectMemory\Console;

use Illuminate\Console\Command;
use ProjectMemory\Support\MemoryConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectMemoryCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            app(MemoryConnection::class)->assertSafe();

            return parent::execute($input, $output);
        } catch (\Throwable $exception) {
            $detail = $exception->getMessage();
            if ($this->getName() === 'ai:mcp') {
                fwrite(STDERR, 'Project memory unavailable: '.$detail."\nInspect live source and run ai:doctor.\n");

                return self::FAILURE;
            }

            return $this->emit([
                'error' => 'project_memory_unavailable',
                'detail' => $detail,
                'source_authoritative' => true,
                'fallback' => 'Inspect live source. Run ai:doctor; ai:scan installs an empty dedicated memory index.',
                'text' => 'Project memory unavailable: '.$detail."\nInspect live source and run ai:doctor.\n",
            ], self::FAILURE);
        }
    }

    protected function emit(array $payload, int $code = self::SUCCESS): int
    {
        if ($this->hasOption('json') && $this->option('json')) {
            $this->output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

            return $code;
        }

        $text = (string) ($payload['text'] ?? '');
        $this->output->write($text);
        if ($text === '' || ! str_ends_with($text, "\n")) {
            $this->output->writeln('');
        }

        return $code;
    }

    protected function budget(): int
    {
        $budget = $this->option('budget');

        return $budget === null || $budget === ''
            ? (int) config('project-memory.context.budget', 4000)
            : (int) $budget;
    }
}
