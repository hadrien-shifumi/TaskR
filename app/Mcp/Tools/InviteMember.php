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
#[Description('Invite a member to a workspace by email. Only the workspace owner may invite. The invitation expires after 7 days and announces a workspace role plus the projects to join, each with its own project role (manager, member or guest — project ownership never rides an invitation). A guest must be announced at least one project; a member without projects joins the workspace but sees no project. At acceptance the invitee joins each announced project team with the announced role; existing team lines are never overwritten.')]
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
            'role' => $schema->string()->enum(['member', 'guest'])->description('Invited workspace role: member (full member of the workspace surfaces) or guest (excluded from workspace-wide surfaces, restricted to the announced projects)'),
            'projects' => $schema->array()->items(
                $schema->object([
                    'project_id' => $schema->string()->description('Project ULID of the workspace')->required(),
                    'role' => $schema->string()->enum(['manager', 'member', 'guest'])->description('Project role granted on acceptance (default: member)'),
                ])->description('A project the invitee joins at acceptance, with its role')
            )->description('Projects to join at acceptance with their project role (required for guests; empty for a member = no project access)'),
            'project_ids' => $schema->array()->items($schema->string())->description('Legacy project perimeter without per-project roles: every id lands as a plain member. Prefer the projects parameter.'),
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
