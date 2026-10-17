<?php

namespace App\Mcp\Tools;

use App\Actions\Comments\DeleteComment as DeleteCommentAction;
use App\Mcp\Support\AgentTool;
use App\Models\Issue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_comment')]
#[Description('Delete a comment from an issue. Only the author of the comment may delete it.')]
class DeleteComment extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'issue_id' => $schema->string()->description('ULID of the issue')->required(),
            'comment_id' => $schema->string()->description('ULID of the comment to delete')->required(),
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

        DeleteCommentAction::run($comment, $this->currentUser($request));

        return Response::structured([
            'deleted' => true,
            'commentId' => $comment->id,
        ]);
    }
}
