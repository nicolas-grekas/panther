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
 * Tracks, through WebDriver BiDi events, the HTTP response that produced the document of each browsing context.
 *
 * Events are consumed lazily: nothing is read from the socket until a response is asked for (or drain() is called),
 * so navigations triggered by any means (get(), clicks, form submissions, history, JavaScript...) are covered.
 *
 * @internal
 */
final class NavigationResponseRecorder
{
    private const EVENTS = [
        'browsingContext.navigationStarted',
        'browsingContext.fragmentNavigated',
        'browsingContext.historyUpdated',
        'browsingContext.contextDestroyed',
        'network.responseCompleted',
    ];

    private const MAX_BODY_SIZE = 10 * 1024 * 1024;
    private const MAX_RESPONSES_PER_CONTEXT = 100;

    private int $commandId = 0;
    private ?string $collector = null;

    /**
     * Per top-level or child context: the navigation that produced the current document.
     *
     * @var array<string, array{id: ?string, urls: array<string, true>, response: ?array, request: ?string, redirectCount?: int}>
     */
    private array $documents = [];

    /**
     * Per context, the last response received for each URL, used for documents restored from the back/forward cache.
     *
     * @var array<string, array<string, array{response: array, request: string}>>
     */
    private array $responsesByUrl = [];

    private function __construct(
        private readonly WebSocketConnection $connection,
    ) {
    }

    public static function connect(string $webSocketUrl, float $timeout = 5.0): self
    {
        $recorder = new self(new WebSocketConnection($webSocketUrl, $timeout));
        $events = self::EVENTS;

        // Events unknown to the remote end make session.subscribe fail as a whole: retry without the optional ones
        foreach ([$events, array_diff($events, ['browsingContext.historyUpdated'])] as $events) {
            $result = $recorder->command('session.subscribe', ['events' => array_values($events)], $timeout);
            if (!isset($result['error'])) {
                // Collect response bodies when supported: the bodies of sub-resources are released as soon as they complete
                $result = $recorder->command('network.addDataCollector', ['dataTypes' => ['response'], 'maxEncodedDataSize' => self::MAX_BODY_SIZE], $timeout);
                $recorder->collector = $result['result']['collector'] ?? null;

                return $recorder;
            }
        }

        throw new RuntimeException(\sprintf('Cannot subscribe to WebDriver BiDi events: %s', $result['message'] ?? $result['error']));
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * Processes the events that have already been received.
     */
    public function drain(): void
    {
        while (null !== $message = $this->connection->receive(0)) {
            $this->process($message);
        }
    }

    /**
     * Returns the response of the document currently displayed in $context, as described by
     * https://w3c.github.io/webdriver-bidi/#type-network-ResponseData, or null if it has none
     * (about:blank, navigation without network request...).
     *
     * BiDi events can be delivered after the classic command that triggered the navigation returned,
     * and a navigation can still be in progress (e.g. after a click): this waits up to $timeout seconds
     * until the document being tracked is the one at the URL returned by $currentUrl.
     *
     * @param \Closure(): string                                  $currentUrl
     * @param \Closure(): (array{type: string, status: int}|null) $navigationTiming the Navigation Timing entry of the current document
     *
     * @return array{response: array, request: string}|null
     */
    public function getResponse(string $context, \Closure $currentUrl, \Closure $navigationTiming, float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;

        // A BiDi round-trip lets the events of the navigations that already started reach us
        $this->command('session.status', [], $timeout);
        $synced = false;
        $timing = false;

        while (true) {
            $document = $this->documents[$context] ?? null;
            $url = self::stripFragment($currentUrl());
            $last = null;

            if (null !== $document && isset($document['urls'][$url])) {
                $response = $document['response'];

                if (null !== $response && !self::isRedirect($response)) {
                    return ['response' => $response, 'request' => $document['request']];
                }

                if (null === $response) {
                    if (!$synced) {
                        // The response may still be on its way
                        $this->command('session.status', [], max(0.1, $deadline - microtime(true)));
                        $synced = true;
                        continue;
                    }

                    // Events can lag far behind (e.g. Chrome after pages with many sub-resources): ask the document itself
                    $timing = false === $timing ? $navigationTiming() : $timing;

                    if (null === $timing || 0 === $timing['status']) {
                        return null; // no network response (about:blank...)
                    }

                    if ('back_forward' === $timing['type'] && isset($this->responsesByUrl[$context][$url])) {
                        // Restored from the back/forward cache, without network events
                        return $this->responsesByUrl[$context][$url];
                    }
                }

                if (null !== $response) {
                    // A redirect is in progress
                    $last = ['response' => $response, 'request' => $document['request']];
                }
            }

            // The events related to the current document have not been received yet, or a navigation is in progress
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return $last;
            }

            if (null !== $message = $this->connection->receive(min($left, 0.05))) {
                $this->process($message);
                $this->drain();
            }
        }
    }

    /**
     * Returns the raw body of a response returned by getResponse(), if the remote end collected it.
     *
     * @param string|null $charset The charset of the response, as text bodies can be sent decoded (Firefox does)
     */
    public function getBody(string $request, ?string $charset, float $timeout): ?string
    {
        if (null === $this->collector) {
            return null;
        }

        $result = $this->command('network.getData', ['dataType' => 'response', 'request' => $request], $timeout);
        $bytes = $result['result']['bytes'] ?? null;

        switch ($bytes['type'] ?? null) {
            case 'base64':
                return base64_decode($bytes['value'], true) ?: null;

            case 'string':
                $body = $bytes['value'];
                if (null !== $charset && !\in_array(strtolower($charset), ['utf-8', 'utf8'], true) && \function_exists('mb_convert_encoding')) {
                    try {
                        $body = mb_convert_encoding($body, $charset, 'UTF-8');
                    } catch (\ValueError) {
                        // unknown charset: keep the decoded body
                    }
                }

                return $body;

            default:
                return null; // not collected, evicted, too large...
        }
    }

    private function process(array $message): void
    {
        $params = $message['params'] ?? [];
        $context = $params['context'] ?? null;

        if ('event' !== ($message['type'] ?? null) || !\is_string($context)) {
            return;
        }

        switch ($message['method']) {
            case 'browsingContext.navigationStarted':
                $this->documents[$context] = ['id' => $params['navigation'] ?? null, 'urls' => [self::stripFragment($params['url']) => true], 'response' => null, 'request' => null];
                break;

            case 'network.responseCompleted':
                $navigation = $params['navigation'] ?? null;
                $request = $params['request']['request'];
                if (null === $navigation) {
                    $this->release($request); // not a navigation request (sub-resource, fetch()...)
                    break;
                }

                $document = $this->documents[$context] ?? null;
                if (null === $document || $document['id'] !== $navigation) {
                    // The navigationStarted event has not been seen (yet): start tracking from here
                    $document = ['id' => $navigation, 'urls' => [], 'response' => null, 'request' => null];
                }

                $url = self::stripFragment($params['response']['url']);
                $document['urls'][$url] = true;
                // Events of the hops of a redirection can be received out of order
                if (($params['redirectCount'] ?? 0) < ($document['redirectCount'] ?? -1)) {
                    $this->release($request);
                    $this->documents[$context] = $document;
                    break;
                }
                if (null !== $document['request'] && $document['request'] !== $request && !$this->isKept($document['request'])) {
                    $this->release($document['request']);
                }
                $document['response'] = $params['response'];
                $document['request'] = $request;
                $document['redirectCount'] = $params['redirectCount'] ?? 0;
                $this->documents[$context] = $document;

                if (!self::isRedirect($params['response'])) {
                    if (null !== $previous = $this->responsesByUrl[$context][$url]['request'] ?? null) {
                        unset($this->responsesByUrl[$context][$url]);
                        if ($previous !== $request) {
                            $this->release($previous);
                        }
                    }
                    $this->responsesByUrl[$context][$url] = ['response' => $params['response'], 'request' => $request];
                    if (\count($this->responsesByUrl[$context]) > self::MAX_RESPONSES_PER_CONTEXT) {
                        $evicted = array_key_first($this->responsesByUrl[$context]);
                        $this->release($this->responsesByUrl[$context][$evicted]['request']);
                        unset($this->responsesByUrl[$context][$evicted]);
                    }
                }
                break;

            case 'browsingContext.fragmentNavigated':
            case 'browsingContext.historyUpdated':
                if (isset($this->documents[$context])) {
                    $this->documents[$context]['urls'][self::stripFragment($params['url'])] = true;
                }
                break;

            case 'browsingContext.contextDestroyed':
                foreach ($this->responsesByUrl[$context] ?? [] as ['request' => $request]) {
                    $this->release($request);
                }
                unset($this->documents[$context], $this->responsesByUrl[$context]);
                break;
        }
    }

    /**
     * Releases the body collected for a request (fire and forget).
     */
    private function release(string $request): void
    {
        if (null !== $this->collector) {
            $this->connection->send(['id' => ++$this->commandId, 'method' => 'network.disownData', 'params' => ['dataType' => 'response', 'collector' => $this->collector, 'request' => $request]]);
        }
    }

    private function isKept(string $request): bool
    {
        foreach ($this->responsesByUrl as $responses) {
            foreach ($responses as $response) {
                if ($response['request'] === $request) {
                    return true;
                }
            }
        }

        return false;
    }

    private function command(string $method, array $params, float $timeout): array
    {
        $id = ++$this->commandId;
        $this->connection->send(['id' => $id, 'method' => $method, 'params' => (object) $params]);

        $deadline = microtime(true) + $timeout;
        while (0 < $left = $deadline - microtime(true)) {
            if (null === $message = $this->connection->receive($left)) {
                break;
            }
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
            $this->process($message);
        }

        throw new RuntimeException(\sprintf('Timeout while waiting for the result of the "%s" WebDriver BiDi command.', $method));
    }

    private static function isRedirect(array $response): bool
    {
        return \in_array($response['status'] ?? 0, [301, 302, 303, 307, 308], true);
    }

    private static function stripFragment(string $url): string
    {
        return false === ($i = strpos($url, '#')) ? $url : substr($url, 0, $i);
    }
}
