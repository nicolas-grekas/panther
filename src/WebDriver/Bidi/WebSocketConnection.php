<?php

/*
 * This file is part of the Panther project.
 *
 * (c) Kévin Dunglas <kevin@dunglas.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Symfony\Component\Panther\WebDriver\Bidi;

use Symfony\Component\Panther\Exception\RuntimeException;

/**
 * A minimal blocking RFC 6455 client, enough to talk WebDriver BiDi to a local or remote driver.
 *
 * @internal
 */
final class WebSocketConnection
{
    /** @var resource */
    private $socket;
    private string $buffer = '';
    private string $message = '';

    public function __construct(string $url, float $timeout = 5.0)
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || !\in_array($parts['scheme'] ?? '', ['ws', 'wss'], true)) {
            throw new RuntimeException(\sprintf('Invalid WebSocket URL "%s".', $url));
        }

        $secure = 'wss' === $parts['scheme'];
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        $socket = @stream_socket_client(($secure ? 'tls://' : 'tcp://').$host.':'.$port, $errno, $error, $timeout);
        if (false === $socket) {
            throw new RuntimeException(\sprintf('Cannot connect to "%s": %s', $url, $error));
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));

        $key = base64_encode(random_bytes(16));
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $this->write("GET $path HTTP/1.1\r\nHost: $host:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $headers = '';
        while (false === $end = strpos($headers, "\r\n\r\n")) {
            $chunk = fread($this->socket, 8192);
            if (false === $chunk || '' === $chunk) {
                throw new RuntimeException(\sprintf('The WebSocket handshake with "%s" failed.', $url));
            }
            $headers .= $chunk;
        }
        $this->buffer = substr($headers, $end + 4);

        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('{^HTTP/1\.1 101 }', $headers) || !preg_match('{^Sec-WebSocket-Accept:\s*'.preg_quote($accept).'\s*$}mi', $headers)) {
            throw new RuntimeException(\sprintf('The WebSocket handshake with "%s" failed: %s', $url, strtok($headers, "\r")));
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function send(array $message): void
    {
        $this->sendFrame(0x1, json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Returns the next message, or null if none arrived within $timeout seconds (0 means: don't wait).
     */
    public function receive(float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            while (null !== $frame = $this->readFrame()) {
                [$fin, $opcode, $payload] = $frame;

                switch ($opcode) {
                    case 0x0:
                    case 0x1:
                    case 0x2:
                        $this->message .= $payload;
                        if ($fin) {
                            $message = $this->message;
                            $this->message = '';

                            return json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
                        }
                        break;
                    case 0x8:
                        $this->close();

                        throw new RuntimeException('The WebSocket connection has been closed by the remote end.');
                    case 0x9:
                        $this->sendFrame(0xA, $payload);
                        break;
                }
            }

            if (!\is_resource($this->socket)) {
                throw new RuntimeException('The WebSocket connection is closed.');
            }

            $left = max(0.0, $deadline - microtime(true));
            $read = [$this->socket];
            $write = $except = null;
            if (!$this->hasBufferedTlsData() && !@stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1e6))) {
                return null;
            }

            $chunk = fread($this->socket, 65536);
            if (false === $chunk || ('' === $chunk && feof($this->socket))) {
                $this->close();

                throw new RuntimeException('The WebSocket connection has been closed by the remote end.');
            }
            $this->buffer .= $chunk;
        }
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    private function hasBufferedTlsData(): bool
    {
        return (stream_get_meta_data($this->socket)['unread_bytes'] ?? 0) > 0;
    }

    /**
     * @return array{bool, int, string}|null
     */
    private function readFrame(): ?array
    {
        $length = \strlen($this->buffer);
        if ($length < 2) {
            return null;
        }

        $byte0 = \ord($this->buffer[0]);
        $byte1 = \ord($this->buffer[1]);
        $payloadLength = $byte1 & 0x7F;
        $offset = 2;

        if (126 === $payloadLength) {
            if ($length < 4) {
                return null;
            }
            $payloadLength = unpack('n', $this->buffer, 2)[1];
            $offset = 4;
        } elseif (127 === $payloadLength) {
            if ($length < 10) {
                return null;
            }
            $payloadLength = unpack('J', $this->buffer, 2)[1];
            $offset = 10;
        }

        $mask = null;
        if ($byte1 & 0x80) {
            if ($length < $offset + 4) {
                return null;
            }
            $mask = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $payloadLength);
        $this->buffer = substr($this->buffer, $offset + $payloadLength);

        if (null !== $mask) {
            $payload ^= str_repeat($mask, intdiv($payloadLength, 4) + 1);
            $payload = substr($payload, 0, $payloadLength);
        }

        return [(bool) ($byte0 & 0x80), $byte0 & 0x0F, $payload];
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $length = \strlen($payload);
        $header = \chr(0x80 | $opcode);

        if ($length < 126) {
            $header .= \chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= \chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= \chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = $payload ^ str_repeat($mask, intdiv($length, 4) + 1);

        $this->write($header.$mask.substr($masked, 0, $length));
    }

    private function write(string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($this->socket, $data);
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Cannot write to the WebSocket connection.');
            }
            $data = substr($data, $written);
        }
    }
}
