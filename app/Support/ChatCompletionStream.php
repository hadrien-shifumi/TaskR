<?php

namespace App\Support;

use Psr\Http\Message\StreamInterface;

/**
 * The streamed half of a chat completion: reads the OpenAI-compatible
 * SSE body incrementally through the chunk parser and yields the
 * normalized events the tool-calling loop consumes — content deltas,
 * merged tool calls, the round's terminal finish reason and usage.
 *
 * The body is closed whether the events are consumed to the end or the
 * consumer stops early (a generator's abandonment runs the finally).
 */
final class ChatCompletionStream implements \IteratorAggregate
{
    public function __construct(
        private readonly StreamInterface $body,
        private readonly ChatChunkParser $parser = new ChatChunkParser,
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function getIterator(): \Generator
    {
        return $this->events();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function events(): \Generator
    {
        try {
            while (! $this->body->eof()) {
                $chunk = $this->body->read(8192);

                if ($chunk === '') {
                    break;
                }

                yield from $this->parser->feed($chunk);
            }

            yield from $this->parser->finish();
        } finally {
            $this->body->close();
        }
    }
}
