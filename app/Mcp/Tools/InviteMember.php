<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\InviteMember as InviteMemberAction;
use App\Enums\WorkspaceRole;
use App\Mcp\Support\AgentTool;
use App\Mcp\Support\VisibleTo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[VisibleTo(WorkspaceRole::Owner)]
#[Name('invite_member')]
#[Description('Invite a member to a workspace by email. Only the workspace owner may invite. The invitation expires after 7 days. Both roles may carry a project perimeter: required for guests, optional for members (an empty perimeter keeps the full workspace access).')]
class InviteMember extends AgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->description('ULID of the workspace')->required(),
            'email' => $schema->string()->description('Email address of the person to invite')->required(),
            'role' => $schema->string()->enum(['member', 'guest'])->description('Invited role: member (full member; can be restricted to project_ids) or guest (restricted to project_ids, excluded from workspace-wide surfaces)'),
            'project_ids' => $schema->array($schema->string())->description('Project perimeter: at least one project ULID of the workspace (required for guests, optional for members — empty means full workspace access)'),
        ];
    }

    protected function execute(Request $request): Response|ResponseFactory
    {
        $workspace = $this->resolveWorkspace($request);

        $user = $this->currentUser($request);
        if (! $workspace->hasOwner($user)) {
            throw new AuthorizationException('Only the workspace owner may invite members.');
        }

        $action = app(InviteMemberAction::class);
        $data = $request->validate($action->rules());

        $invitation = $action->handle($workspace, $data);

        return Response::structured([
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'expiresAt' => $invitation->expires_at?->toISOString(),
            ],
        ]);
    }
}
