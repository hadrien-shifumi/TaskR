<?php

namespace Tests\Unit\Chat;

use App\Chat\Exceptions\ProviderFailure;
use App\Support\Chat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The streaming half of the chat client: the request it sends (streaming
 * on, usage asked, tools carried, token header), the events the loop
 * consumes, and the provider failure mapped out of a non-2xx response.
 */
class ChatStreamingTest extends TestCase
{
    private const ENDPOINT = 'https://chat-provider.test/v1/chat/completions';

    private function client(): Chat
    {
        return new Chat([
            'base_url' => 'https://chat-provider.test/v1',
            'api_key' => 'stream-secret',
            'model' => 'mistral-large-latest',
        ]);
    }

    public function test_streams_content_and_the_terminal_usage(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Bo\"}}]}\n\n".
                "data: {\"choices\":[{\"delta\":{\"content\":\"njour\"}}]}\n\n".
                "data: {\"choices\":[{\"delta\":{\"content\":\"TaskR\"}}]}\n\n".
                "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n".
                "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":12,\"completion_tokens\":34,\"total_tokens\":46}}\n\n",
                200,
            ),
        ]);

        $content = '';
        $finishReason = null;
        $usage = null;

        foreach ($this->client()->completeStream([
            ['role' => 'user', 'content' => 'TaskR'],
        ]) as $event) {
            if ($event['type'] === 'content') {
                $content .= $event['text'];
            }

            if ($event['type'] === 'done') {
                $finishReason = $event['finishReason'] ?? $finishReason;
                $usage = $event['usage'] ?? $usage;
            }
        }

        $this->assertSame('BonjourTaskR', $content);
        $this->assertSame('stop', $finishReason);
        $this->assertSame(['prompt_tokens' => 12, 'completion_tokens' => 34, 'total_tokens' => 46], $usage);
    }

    public function test_builds_the_streaming_request_with_the_tools_carried(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(
                "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
                200,
            ),
        ]);

        $this->client()->completeStream(
            [
                ['role' => 'system', 'content' => "You are TaskR's assistant."],
                ['role' => 'user', 'content' => 'TaskR'],
            ],
            [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'list_projects',
                        'description' => 'List the projects of the workspace.',
                        'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
                    ],
                ],
            ],
        )->events();

        Http::assertSent(function (Request $request): bool {
            /** @var array<string, mixed> $body */
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer stream-secret')
                && $body['model'] === 'mistral-large-latest'
                && $body['stream'] === true
                && $body['stream_options'] === ['include_usage' => true]
                && $body['tool_choice'] === 'auto'
                && $body['tools'][0]['function']['name'] === 'list_projects'
                && $body['messages'][0]['role'] === 'system'
                && $body['messages'][1]['role'] === 'user';
        });
    }

    public function test_a_completion_without_tools_sends_none(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(
                "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
                200,
            ),
        ]);

        $this->client()->completeStream([
            ['role' => 'user', 'content' => 'TaskR'],
        ])->events();

        Http::assertSent(function (Request $request): bool {
            /** @var array<string, mixed> $body */
            $body = $request->data();

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_an_http_error_fails_openly_never_silently(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['error' => ['message' => 'model overloaded']], 503),
        ]);

        try {
            iterator_to_array($this->client()->completeStream([
                ['role' => 'user', 'content' => 'TaskR'],
            ])->events());

            $this->fail('The provider failure did not surface.');
        } catch (ProviderFailure $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
            $this->assertSame(ProviderFailure::MESSAGE_KEY, $exception->messageKey());
        }
    }
}
