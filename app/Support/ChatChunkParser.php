<?php

namespace App\Support;

use App\Chat\Exceptions\ProviderFailure;

/**
 * The pure SSE parser behind streamed OpenAI-compatible chat completions:
 * it turns raw response bytes into normalized events, buffering partial
 * lines (the transport reads fixed-size chunks, never event frames) and
 * merging fragmentary `tool_calls` deltas by index.
 *
 * Events yielded, in order:
 * - `['type' => 'content', 'text' => string]` — one per non-empty content delta;
 * - `['type' => 'tool_calls', 'calls' => list<array{id: string, name: string, arguments: array<string, mixed>}>]`
 *   — once per round, when the stream reports `finish_reason: tool_calls`; the
 *   merged argument fragments decode into an array, a broken payload decodes
 *   to empty and the tool's own validation reports the problem to the model;
 * - `['type' => 'done', 'finishReason' => ?string, 'usage' => ?array]` — when the
 *   round ends (any `finish_reason`) or on the `data: [DONE]` sentinel; `usage`
 *   is whatever the provider reported (`stream_options.include_usage`), carried
 *   on whichever event sees it first.
 *
 * Held across `feed()` calls: the incomplete tool call fragments (merged in
 * place) and any trailing line fragment. `finish()` flushes the latter —
 * some providers close the body without a final newline. A `done` event is
 * emitted exactly once.
 */
final class ChatChunkParser
{
    /** @var array<int, array{id: ?string, name: ?string, arguments: string}> */
    private array $toolCalls = [];

    private string $buffer = '';

    /**
     * Consume a raw chunk, return the events it completes.
     *
     * @return list<array<string, mixed>>
     */
    public function feed(string $chunk): iterable
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            foreach ($this->line($line) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Flush a trailing line the body ended without.
     *
     * @return list<array<string, mixed>>
     */
    public function finish(): iterable
    {
        if ($this->buffer === '') {
            return [];
        }

        $line = $this->buffer;
        $this->buffer = '';

        return $this->line($line);
    }

    /**
     * One SSE line: `data:` payloads drive the events, comments, `id:` and
     * `event:` lines are noise the transport detail never needs.
     *
     * @return list<array<string, mixed>>
     */
    private function line(string $line): array
    {
        $line = rtrim($line, "\r");

        if (! str_starts_with($line, 'data:')) {
            return [];
        }

        $payload = trim(substr($line, 5));

        if ($payload === '') {
            return [];
        }

        if ($payload === '[DONE]') {
            // Terminal silence: the round's `done` event already fired with
            // its finish_reason, and the stream's end is the loop's stop
            // signal — a second event would read as another round.
            return [];
        }

        $chunk = json_decode($payload, true);

        if (! is_array($chunk)) {
            return [];
        }

        if (array_key_exists('error', $chunk)) {
            // Some providers surface a mid-stream error inside a 200
            // response: fail the turn loudly instead of answering with a
            // silent, empty stream.
            throw new ProviderFailure(strval($chunk['error']['message'] ?? json_encode($chunk['error'])));
        }

        $events = [];
        $choice = $chunk['choices'][0] ?? null;
        $choice = is_array($choice) ? $choice : [];

        $text = strval($choice['delta']['content'] ?? '');

        if ($text !== '') {
            $events[] = ['type' => 'content', 'text' => $text];
        }

        foreach ((array) ($choice['delta']['tool_calls'] ?? []) as $position => $fragment) {
            $fragment = (array) $fragment;

            // The call's own `index` field keys the merge — never the
            // fragment's position in this delta's array.
            $this->mergeToolCallFragment((int) ($fragment['index'] ?? $position), $fragment);
        }

        // At most one `done` per chunk: finish_reason and the usage block
        // (arriving either together or in a trailing choices-less chunk)
        // ride the same event.
        $finishReason = $choice['finish_reason'] ?? null;
        $usage = is_array($chunk['usage'] ?? null) ? $chunk['usage'] : null;

        if ($finishReason === 'tool_calls') {
            $events[] = ['type' => 'tool_calls', 'calls' => $this->completeToolCalls()];
        }

        if ($finishReason !== null || $usage !== null) {
            $events[] = ['type' => 'done', 'finishReason' => $finishReason, 'usage' => $usage];
        }

        return $events;
    }

    /**
     * Accumulate one delta fragment of a tool call (OpenAI streams the
     * name and the arguments JSON in pieces, keyed by the call's index in
     * the round).
     *
     * @param  array<string, mixed>  $fragment
     */
    private function mergeToolCallFragment(int $index, array $fragment): void
    {
        $function = (array) ($fragment['function'] ?? []);

        $this->toolCalls[$index] ??= ['id' => null, 'name' => null, 'arguments' => ''];

        if ($fragment['id'] ?? null) {
            $this->toolCalls[$index]['id'] = strval($fragment['id']);
        }

        if (! empty($function['name'])) {
            $this->toolCalls[$index]['name'] = strval($function['name']);
        }

        if (isset($function['arguments'])) {
            $this->toolCalls[$index]['arguments'] .= strval($function['arguments']);
        }
    }

    /**
     * The round's tool calls, in declaration order (index ids grow). The
     * merged argument fragments decode into an array — an argument payload
     * the provider broke decodes to empty, and the tool's own validation
     * is what reports the problem to the model.
     *
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function completeToolCalls(): array
    {
        $calls = [];

        foreach ($this->toolCalls as $index => ['id' => $id, 'name' => $name, 'arguments' => $arguments]) {
            $calls[] = [
                'id' => $id ?? 'call_'.strval($index),
                'name' => $name ?? '',
                'arguments' => is_array($decoded = json_decode($arguments, true)) ? $decoded : [],
            ];
        }

        return $calls;
    }
}
