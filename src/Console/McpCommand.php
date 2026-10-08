<?php

namespace ProjectMemory\Console;

use ProjectMemory\Mcp\McpServer;
use ProjectMemory\Mcp\StdioTransport;

class McpCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:mcp';

    protected $description = 'Serve project-memory MCP using newline-delimited JSON on stdin/stdout';

    public function handle(McpServer $server, StdioTransport $transport): int
    {
        $transport->serve($server, STDIN, STDOUT);

        return self::SUCCESS;
    }
}
