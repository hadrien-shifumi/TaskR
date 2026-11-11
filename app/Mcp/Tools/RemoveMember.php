<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\RemoveMember as RemoveMemberAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[VisibleTo(WorkspaceRole::Owner)]
#[Name('remove_member')]
#[Description('Remove a member from a workspace. Only the workspace owner may remove members. The owner cannot remove themselves.')]
class RemoveMember extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'user_id' => $schema->string()->description('ID of the user to remove')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may remove members.');
        }

        $member = User::query()->findOrFail($request->get('user_id'));

        RemoveMemberAction::run($workspace, $member, $user);

        return Response::structured([
            'removed' => true,
            'userId' => $member->id,
        ]);
    }
}
