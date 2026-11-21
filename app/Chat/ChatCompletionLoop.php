<?php

namespace App\Chat;

use App\Chat\Exceptions\ChatNotConfigured;
use App\Chat\Exceptions\ProviderFailure;
use App\Chat\Exceptions\ToolRoundsExhausted;
use App\Chat\Tools\ChatTool;
use App\Chat\Tools\ChatToolRegistry;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Chat;

/**
 * The server side of one chat turn: call the provider, stream the
 * fragments to the thread's private channel, satisfy the tool calls the
 * model asks for, and settle the persisted assistant message.
 *
 * The model only ever reads what the requesting user could read via the
 * screens: the tool set is the read-only whitelist, every tool executes
 * under the user's real identity, and the workspace context wraps each
 * call (set before, flushed after) so one conversation's tools never
 * color another turn's answers.
 */
final class ChatCompletionLoop
{
    /**
     * Tool-calling rounds one turn may spend: an honest "I stopped
     * exploring" beats an unbounded conversation with a provider.
     */
    public const MAX_TOOL_ROUNDS = 6;

    /**
     * The serialized tool results injected back into the model's context
     * are cut here — enough to ground an answer, never enough to blow
     * the context window with one verbose tool.
     */
    public const TOOL_RESULT_MAX_CHARS = 4000;

    /**
     * How much of the thread's settled history rides each provider call.
     */
    public const HISTORY_WINDOW = 20;

    public function __construct(
        private readonly ChatToolRegistry $tools,
        private readonly ChatPublisher $publisher,
    ) {}

    /**
     * Run the turn for one thread's pending assistant message: stream the
     * response, satisfy the tool rounds, settle the message complete —
     * body, tool journal and usage persisted, the final fragment
     * published. A provider failure climbs to the caller (the queued job
     * finalizes the turn); a tool failure answers the model in place.
     *
     * @throws ChatNotConfigured
     * @throws ProviderFailure
     * @throws ToolRoundsExhausted
     */
    public function run(ChatThread $thread, ChatMessage $assistantMessage): ChatMessage
    {
        $chat = Chat::forWorkspace($thread->workspace);

        if (! $chat->enabled()) {
            throw new ChatNotConfigured;
        }

        $user = $thread->user;
        $workspace = $thread->workspace;

        $messages = $this->messages($thread);
        $definitions = $this->tools->definitions();

        $content = '';
        /** @var list<array<string, mixed>> $toolJournal */
        $toolJournal = [];
        $usage = [];
        $settledRounds = 0;

        while (true) {
            $roundContent = '';
            /** @var list<array{id: string, name: string, arguments: array<string, mixed>}>|null $roundToolCalls */
            $roundToolCalls = null;
            /** @var string|null $finishReason */
            $finishReason = null;

            foreach ($chat->completeStream($messages, $definitions) as $event) {
                if ($event['type'] === 'content') {
                    $roundContent .= $event['text'];
                    $this->publisher->chunk($thread->id, $assistantMessage->id, $event['text']);
                } elseif ($event['type'] === 'tool_calls') {
                    $roundToolCalls = $event['calls'];
                } elseif ($event['type'] === 'done') {
                    if (isset($event['finishReason']) && is_string($event['finishReason'])) {
                        $finishReason = $event['finishReason'];
                    }

                    if (is_array($event['usage'] ?? null)) {
                        $usage = $this->mergeUsage($usage, $event['usage']);
                    }
                }
            }

            $content .= $roundContent;

            // A round that ends without tool calls is the turn's final
            // answer, whatever finish reason the provider chose.
            if ($roundToolCalls === null) {
                return $this->settleComplete($assistantMessage, $content, $toolJournal, $usage);
            }

            // The turn was still exploring past the budget — stop here
            // with what was found so far, as a failed turn.
            if ($settledRounds >= self::MAX_TOOL_ROUNDS) {
                throw new ToolRoundsExhausted;
            }

            $settledRounds++;

            // The assistant's tool-call round joins the conversation, then
            // one result per call, then the loop calls the provider again.
            $messages[] = array_filter([
                'role' => 'assistant',
                'content' => $roundContent,
                'tool_calls' => array_map(
                    fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => json_encode($call['arguments']),
                        ],
                    ],
                    $roundToolCalls,
                ),
            ], fn ($value) => $value !== null);

            foreach ($roundToolCalls as $call) {
                $callId = strval($call['id']);
                $name = strval($call['name']);

                $this->publisher->toolStarted($thread->id, $assistantMessage->id, $callId, $name);

                $result = $this->executeTool($workspace, $user, $name, $call['arguments']);

                $toolJournal[] = [
                    'callId' => $callId,
                    'name' => $name,
                    'ok' => $result['ok'],
                    'summary' => $result['summary'],
                ];

                $this->publisher->toolResult($thread->id, $assistantMessage->id, $callId, $name, $result['ok'], $result['summary']);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $this->truncate($this->toolResultPayload($result)),
                ];
            }
        }
    }

    /**
     * The message conversation one provider call reads: the system
     * prompt, the settled window of the thread's history, and the turn's
     * messages so far.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(ChatThread $thread): array
    {
        return [
            ['role' => 'system', 'content' => strval(__('chat.system_prompt'))],
            ...$thread->messages()
                ->orderBy('created_at')
                ->get()
                ->filter(fn (ChatMessage $message): bool => $message->role === ChatMessageRole::User
                    || $message->status === ChatMessageStatus::Complete)
                ->slice(-self::HISTORY_WINDOW)
                ->values()
                ->map(fn (ChatMessage $message): array => [
                    'role' => $message->role->value,
                    'content' => strval($message->body),
                ])
                ->all(),
        ];
    }

    /**
     * One tool call, under the requesting user's identity: the tool
     * itself wraps the action with the workspace context (set before,
     * flushed after, failure or not) — the loop stays readable, the
     * isolation stays in one place.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, summary: string, result: array<string, mixed>}
     */
    private function executeTool(Workspace $workspace, User $user, string $name, array $arguments): array
    {
        $tool = $this->tools->find($name);

        if ($tool === null) {
            // A hallucinated tool name never breaks the turn: the model
            // reads the refusal and answers with what it has.
            return [
                'ok' => false,
                'summary' => $name,
                'result' => [
                    'error' => 'unknown_tool',
                    'message' => strval(__('chat.tools.unknown_tool')),
                ],
            ];
        }

        $data = $tool->run($arguments, $workspace, $user);
        $ok = (bool) ($data['ok'] ?? false);

        return [
            'ok' => $ok,
            'summary' => $this->summary($tool, $ok),
            'result' => $data,
        ];
    }

    /**
     * The displayable label a tool call publishes: the tool's readable
     * name in the application language — the raw tool payload stays
     * server-side.
     */
    private function summary(ChatTool $tool, bool $ok): string
    {
        if (! $ok) {
            return strval(__('chat.tools.failed'));
        }

        return strval(__('chat.tools.'.$tool->name()));
    }

    /**
     * The JSON payload the tool's result injects into the model's context.
     *
     * @param  array{ok: bool, summary: string, result: array<string, mixed>}  $result
     */
    private function toolResultPayload(array $result): string
    {
        return strval(json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The displayable truncation of one tool payload.
     */
    private function truncate(string $payload): string
    {
        if (mb_strlen($payload) <= self::TOOL_RESULT_MAX_CHARS) {
            return $payload;
        }

        return mb_substr($payload, 0, self::TOOL_RESULT_MAX_CHARS).'…';
    }

    /**
     * The accumulated usage of the turn: the provider's numeric counters
     * summed over every round the loop consumed.
     *
     * @param  array<string, mixed>  $carried
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    private function mergeUsage(array $carried, array $round): array
    {
        $merged = $carried;

        foreach ($round as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $merged[$key] = ($merged[$key] ?? 0) + $value;
        }

        return $merged;
    }

    /**
     * The message settles complete: the final body, the tool journal for
     * the interface, the usage counters. The final state publishes last,
     * once the persistence says so.
     *
     * @param  list<array<string, mixed>>  $toolJournal
     */
    private function settleComplete(ChatMessage $assistantMessage, string $content, array $toolJournal, array $usage): ChatMessage
    {
        $assistantMessage->forceFill([
            'body' => $content,
            'tool_calls' => $toolJournal === [] ? null : $toolJournal,
            'usage' => $usage === [] ? null : $usage,
            'status' => ChatMessageStatus::Complete,
        ])->save();

        $this->publisher->done(
            strval($assistantMessage->thread_id),
            $assistantMessage->id,
            $content,
            $usage === [] ? null : $usage,
        );

        return $assistantMessage;
    }
}
