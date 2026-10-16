<?php

namespace App\Mcp\Tools;

use App\Actions\Workspaces\InviteMember as InviteMemberAction;
use App\Mcp\Support\AgentTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('invite_member')]
#[Description('Invite a member to a workspace by email. Only the workspace owner may invite. The invitation expires after 7 days.')]
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
