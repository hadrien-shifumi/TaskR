<?php

namespace App\Mcp\Tools;

use App\Actions\Comments\AddComment as AddCommentAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_comment')]
#[Description(
    'Add a comment to an issue. The authenticated user is the author.',
)]
class AddComment extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema
                ->string()
                ->description('ULID of the workspace')
                ->required(),
            'issue_id' => $schema
                ->string()
                ->description('ULID of the issue to comment on')
                ->required(),
            'body' => $schema
                ->string()
                ->description('Comment body (max 20000 chars)')
                ->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));

        $action = app(AddCommentAction::class);
        $body = $request->validate($action->rules())['body'];

        $comment = $action->handle($issue, $this->currentUser($request), $body);

        return Response::structured([
            'comment' => [
                'id' => $comment->id,
                'issueId' => $comment->issue_id,
                'authorId' => $comment->author_id,
                'body' => $comment->body,
                'createdAt' => $comment->created_at->toISOString(),
            ],
        ]);
    }
}
