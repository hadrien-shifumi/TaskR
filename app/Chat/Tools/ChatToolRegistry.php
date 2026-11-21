<?php

namespace App\Chat\Tools;

/**
 * The assistant's whitelist: the read-only tools a turn may call, in the
 * order the model discovers them. Every entry wraps the exact shared
 * action the screens run — the assistant reads with the requesting
 * user's own eyes and never writes. Writes are the V2 conversation;
 * destructive calls are outside this registry's vocabulary.
 */
class ChatToolRegistry
{
    /** @var list<ChatTool> */
    private array $tools;

    /**
     * @param  list<ChatTool>|null  $tools  Defaults to the full whitelist.
     */
    public function __construct(?array $tools = null)
    {
        $this->tools = $tools ?? [
            new SearchKnowledge,
            new ListKnowledge,
            new GetKnowledge,
            new ListProjects,
            new ListIssues,
            new GetIssue,
            new ListComments,
        ];
    }

    /**
     * @return list<ChatTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (ChatTool $tool) => $tool->name(), $this->tools);
    }

    public function find(string $name): ?ChatTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * The OpenAI function-calling definitions of the whitelist, in
     * registry order.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(fn (ChatTool $tool): array => $tool->definition(), $this->tools);
    }
}
