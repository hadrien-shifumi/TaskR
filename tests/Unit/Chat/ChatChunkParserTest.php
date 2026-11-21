<?php

namespace Tests\Unit\Chat;

use App\Support\ChatChunkParser;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The pure SSE parser behind the streamed chat completions: OpenAI-style
 * `data:` lines with fragmentary deltas (`content`, then `tool_calls`
 * accumulated by index), the `usage` block of the final chunk, the
 * `[DONE]` sentinel — and the chunk boundaries anywhere inside a line,
 * since the transport reads by fixed size, never by events.
 */
class ChatChunkParserTest extends BaseTestCase
{
    public function test_parses_content_deltas_from_a_single_line(): void
    {
        $parser = new ChatChunkParser;

        $events = $parser->feed("data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n");

        $this->assertSame([['type' => 'content', 'text' => 'Hello']], $events);
    }

    public function test_parses_content_accumulated_across_calls(): void
    {
        $parser = new ChatChunkParser;

        $this->assertSame(
            [['type' => 'content', 'text' => 'Hel']],
            $parser->feed("data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n")
        );
        $this->assertSame(
            [['type' => 'content', 'text' => 'lo']],
            $parser->feed("data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n")
        );
    }

    public function test_buffers_a_json_line_split_across_chunks(): void
    {
        $parser = new ChatChunkParser;

        $first = $parser->feed('data: {"choices":[{"delta":{"content":"Bo');
        $this->assertSame([], $first);

        $second = $parser->feed("nj ou\"}}]}\n\ndata: {\"choices\":[{\"delta\":{\"content\":\"r !\"}}]}\n\n");
        $this->assertSame([
            ['type' => 'content', 'text' => 'Bonj ou'],
            ['type' => 'content', 'text' => 'r !'],
        ], $second);
    }

    public function test_merges_fragmentary_tool_call_deltas_by_index(): void
    {
        $parser = new ChatChunkParser;

        $events = $parser->feed(
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"type\":\"function\",\"function\":{\"name\":\"search_knowledge\",\"arguments\":\"\"}}]}}]}\n\n".
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{\\\"query\\\":\\\"reposit\"}}]}}]}\n\n".
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"oire\\\"}\"}}]}}]}\n\n".
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n"
        );

        $this->assertSame([
            [
                'type' => 'tool_calls',
                'calls' => [
                    [
                        'id' => 'call_1',
                        'name' => 'search_knowledge',
                        'arguments' => ['query' => 'repositoire'],
                    ],
                ],
            ],
            ['type' => 'done', 'finishReason' => 'tool_calls', 'usage' => null],
        ], $events);
    }

    public function test_merges_two_parallel_tool_calls_in_one_round(): void
    {
        $parser = new ChatChunkParser;

        $events = $parser->feed(
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_a\",\"function\":{\"name\":\"list_projects\",\"arguments\":\"{}\"}},{\"index\":1,\"id\":\"call_b\",\"function\":{\"name\":\"list_issues\",\"arguments\":\"{\"}}]}}]}\n\n".
            "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":1,\"function\":{\"arguments\":\"}\"}}]}}]}\n\n".
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n".
            "data: [DONE]\n\n"
        );

        $this->assertCount(2, $events);
        $this->assertSame('tool_calls', $events[0]['type']);
        $this->assertSame([
            ['id' => 'call_a', 'name' => 'list_projects', 'arguments' => []],
            ['id' => 'call_b', 'name' => 'list_issues', 'arguments' => []],
        ], $events[0]['calls']);
        $this->assertSame(['type' => 'done', 'finishReason' => 'tool_calls', 'usage' => null], $events[1]);
    }

    public function test_reports_usage_from_the_final_chunk(): void
    {
        $parser = new ChatChunkParser;

        $events = $parser->feed(
            "data: {\"choices\":[{\"delta\":{\"content\":\"Done.\"},\"finish_reason\":\"stop\"}]}\n\n".
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":12,\"completion_tokens\":34,\"total_tokens\":46}}\n\n".
            "data: [DONE]\n\n"
        );

        $this->assertSame([
            ['type' => 'content', 'text' => 'Done.'],
            ['type' => 'done', 'finishReason' => 'stop', 'usage' => null],
            ['type' => 'done', 'finishReason' => null, 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 34, 'total_tokens' => 46]],
        ], $events);
    }

    public function test_ignores_comments_id_lines_and_malformed_data(): void
    {
        $parser = new ChatChunkParser;

        $events = $parser->feed(
            ": keep-alive\n\n".
            "event: message\nid: 42\ndata: not-json\n\n".
            "data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\n"
        );

        $this->assertSame([['type' => 'content', 'text' => 'ok']], $events);
    }

    public function test_missing_content_delta_stays_silent(): void
    {
        $parser = new ChatChunkParser;

        $events = $parser->feed("data: {\"choices\":[{\"delta\":{}}]}\n\n");

        $this->assertSame([], $events);
    }

    public function test_finish_flushes_a_trailing_buffered_line(): void
    {
        $parser = new ChatChunkParser;

        $this->assertSame(
            [['type' => 'content', 'text' => 'head']],
            $parser->feed("data: {\"choices\":[{\"delta\":{\"content\":\"head\"}}]}\n\n")
        );
        // The body closes without the final newline of the last line: the
        // feed stays buffered, the finish flush carries it.
        $this->assertSame(
            [],
            $parser->feed('data: {"choices":[{"delta":{"content":"tail"}}]}')
        );
        $this->assertSame(
            [['type' => 'content', 'text' => 'tail']],
            $parser->finish()
        );
    }

    public function test_done_event_is_emitted_only_once(): void
    {
        $parser = new ChatChunkParser;

        $parser->feed("data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":2}}\n\n");

        $this->assertSame([], $parser->feed("data: [DONE]\n\n"));
    }
}
