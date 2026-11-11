<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\ShowMembersPage as ShowMembersPageAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[VisibleTo(WorkspaceRole::Owner, WorkspaceRole::Member)]
#[IsReadOnly]
#[Name('list_members')]
#[Description('List the members of a workspace and their pending invitations. Full members only: a guest gets forbidden.')]
class ListMembers extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        // The members directory is reserved to full members: the web page
        // refuses guests, the tool mirrors it.
        if (! app(WorkspaceContext::class)->isFullMember($this->currentUser($request))) {
            throw new AuthorizationException('Only full members can list members.');
        }

        $result = ShowMembersPageAction::run($workspace);

        return Response::structured([
            'members' => $result['members'],
            'invitations' => $result['invitations'],
        ]);
    }
}
