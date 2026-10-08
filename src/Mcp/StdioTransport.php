<?php

namespace ProjectMemory\Mcp;

/** Newline-delimited MCP stdio, with symmetric framing for legacy clients. */
class StdioTransport
{
    /** @param resource $input @param resource $output */
    public function serve(McpServer $server, $input, $output): void
    {
        $limit = max(1024, min(16 * 1024 * 1024, (int) config('project-memory.mcp.max_message_bytes', 1024 * 1024)));
        while (! feof($input)) {
            $line = fgets($input, $limit + 2);
            if ($line === false) {
                break;
            }
            if (trim($line) === '') {
                continue;
            }
            $framed = preg_match('/^Content-Length:/i', $line) === 1;
            $body = $line;
            if ($framed) {
                if (! preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', trim($line), $matches)) {
                    $this->write($output, $this->error(-32700, 'Invalid Content-Length header'), true);
                    break;
                }
                $length = (int) $matches[1];
                if ($length > $limit) {
                    $this->write($output, $this->error(-32600, 'Message exceeds configured size limit'), true);
                    break;
                }
                $headersComplete = false;
                $headerBytes = strlen($line);
                while (($header = fgets($input, 8194)) !== false) {
                    $headerBytes += strlen($header);
                    if ($headerBytes > 8192) {
                        break;
                    }
                    if (trim($header) === '') {
                        $headersComplete = true;
                        break;
                    }
                }
                if (! $headersComplete) {
                    $this->write($output, $this->error(-32700, 'Incomplete frame headers'), true);
                    break;
                }
                $body = '';
                while (strlen($body) < $length && ! feof($input)) {
                    $chunk = fread($input, $length - strlen($body));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                }
                if (strlen($body) !== $length) {
                    $this->write($output, $this->error(-32700, 'Incomplete message body'), true);
                    break;
                }
            } elseif (strlen(rtrim($body, "\r\n")) > $limit) {
                $this->write($output, $this->error(-32600, 'Message exceeds configured size limit'), false);
                break;
            }

            try {
                $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
                $response = $decoded instanceof \stdClass
                    ? $server->handle(json_decode($body, true, 64, JSON_THROW_ON_ERROR))
                    : $this->error(-32600, 'Request must be a JSON object; batch requests are unsupported');
            } catch (\JsonException) {
                $response = $this->error(-32700, 'Invalid JSON');
            } catch (\Throwable) {
                $response = $this->error(-32603, 'Internal error; inspect live source and run ai:doctor');
            }
            if ($response !== null) {
                $this->write($output, $response, $framed);
            }
        }
    }

    /** @param resource $output */
    private function write($output, array $response, bool $framed): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $wire = $framed ? 'Content-Length: '.strlen($json)."\r\n\r\n".$json : $json."\n";
        $written = 0;
        while ($written < strlen($wire)) {
            $count = fwrite($output, substr($wire, $written));
            if ($count === false || $count === 0) {
                throw new \RuntimeException('MCP output stream closed.');
            }
            $written += $count;
        }
        fflush($output);
    }

    private function error(int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
