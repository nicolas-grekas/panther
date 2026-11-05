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

    private int $commandId = 0;

    /**
     * Per top-level or child context: the navigation that produced the current document.
     *
     * @var array<string, array{id: ?string, urls: array<string, true>, response: ?array, redirectCount?: int}>
     */
    private array $documents = [];

    /**
     * Per context, the last response received for each URL, used for documents restored from the back/forward cache.
     *
     * @var array<string, array<string, array>>
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
     * @param \Closure(): string $currentUrl
     */
    public function getResponse(string $context, \Closure $currentUrl, float $timeout): ?array
    {
        $deadline = microtime(true) + $timeout;

        // A BiDi round-trip lets the events of the navigations that already started reach us
        $this->command('session.status', [], $timeout);
        $synced = false;

        while (true) {
            $document = $this->documents[$context] ?? null;
            $url = self::stripFragment($currentUrl());
            $last = null;

            if (null !== $document && isset($document['urls'][$url])) {
                $response = $document['response'];

                if (null !== $response && !self::isRedirect($response)) {
                    return $response;
                }

                if (null === $response) {
                    if (!$synced) {
                        // The response may still be on its way
                        $this->command('session.status', [], max(0.1, $deadline - microtime(true)));
                        $synced = true;
                        continue;
                    }

                    // No network response for this navigation: either it has none (about:blank...),
                    // or the document was restored from the back/forward cache
                    return $this->responsesByUrl[$context][$url] ?? null;
                }

                // A redirect is in progress
                $last = $response;
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

    private function process(array $message): void
    {
        $params = $message['params'] ?? [];
        $context = $params['context'] ?? null;

        if ('event' !== ($message['type'] ?? null) || !\is_string($context)) {
            return;
        }

        switch ($message['method']) {
            case 'browsingContext.navigationStarted':
                $this->documents[$context] = ['id' => $params['navigation'] ?? null, 'urls' => [self::stripFragment($params['url']) => true], 'response' => null];
                break;

            case 'network.responseCompleted':
                $navigation = $params['navigation'] ?? null;
                if (null === $navigation) {
                    break; // not a navigation request (sub-resource, fetch()...)
                }

                $document = $this->documents[$context] ?? null;
                if (null === $document || $document['id'] !== $navigation) {
                    // The navigationStarted event has not been seen (yet): start tracking from here
                    $document = ['id' => $navigation, 'urls' => [], 'response' => null];
                }

                $url = self::stripFragment($params['response']['url']);
                $document['urls'][$url] = true;
                // Events of the hops of a redirection can be received out of order
                if (($params['redirectCount'] ?? 0) >= ($document['redirectCount'] ?? -1)) {
                    $document['response'] = $params['response'];
                    $document['redirectCount'] = $params['redirectCount'] ?? 0;
                }
                $this->documents[$context] = $document;

                unset($this->responsesByUrl[$context][$url]);
                $this->responsesByUrl[$context][$url] = $params['response'];
                if (\count($this->responsesByUrl[$context]) > 100) {
                    unset($this->responsesByUrl[$context][array_key_first($this->responsesByUrl[$context])]);
                }
                break;

            case 'browsingContext.fragmentNavigated':
            case 'browsingContext.historyUpdated':
                if (isset($this->documents[$context])) {
                    $this->documents[$context]['urls'][self::stripFragment($params['url'])] = true;
                }
                break;

            case 'browsingContext.contextDestroyed':
                unset($this->documents[$context], $this->responsesByUrl[$context]);
                break;
        }
    }

    private function command(string $method, array $params, float $timeout): array
    {
        $id = ++$this->commandId;
        $this->connection->send(['id' => $id, 'method' => $method, 'params' => $params]);

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
