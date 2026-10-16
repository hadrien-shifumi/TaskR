<?php

namespace App\Mcp\Tools;

use App\Actions\Comments\UpdateComment as UpdateCommentAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_comment')]
#[Description('Update a comment on an issue. Only the author of the comment may update it.')]
class UpdateComment extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'comment_id' => $schema->string()->description('ULID of the comment to update')->required(),
            'body' => $schema->string()->description('New comment body (max 20000 chars)')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $this->resolveWorkspace($request);

        $issue = Issue::query()->findOrFail($request->get('issue_id'));
        $comment = $issue->comments()->findOrFail($request->get('comment_id'));

        $user = $this->currentUser($request);
        if ($comment->author_id !== $user->id) {
            throw new AuthorizationException('You are not the author of this comment.');
        }

        $action = app(UpdateCommentAction::class);
        $body = $request->validate($action->rules())['body'];

        $comment = $action->handle($comment, $body);

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
