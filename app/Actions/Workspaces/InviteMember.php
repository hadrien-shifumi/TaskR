<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\InviteMemberResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class InviteMember
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = request()->route('workspace');

        return $user instanceof User
            && $workspace instanceof Workspace
            && $workspace->hasOwner($user);
    }

    public function rules(): array
    {
        // HTTP resolves the workspace from the route binding, agent tools
        // from the request-scoped WorkspaceContext.
        $workspace = request()->route('workspace') ?? app(WorkspaceContext::class)->get();

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['nullable', 'string', 'in:'.implode(',', array_column(WorkspaceRole::cases(), 'value'))],
            // A guest is onboarded with a perimeter: never empty, always
            // projects of this workspace. A member may carry one too: an
            // empty perimeter keeps the default full workspace access.
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => [
                'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id),
            ],
        ];
    }

    /**
     * Cross-field rule the base validator cannot express: a guest always
     * carries a non-empty scope. Runs as an "after" hook — errors added
     * before the run are wiped by the validator.
     */
    public function afterValidator(Validator $validator): void
    {
        $data = $validator->getData();
        $role = ($data['role'] ?? null) ?: WorkspaceRole::Member->value;
        $projectIds = array_values($data['project_ids'] ?? []);

        if ($role === WorkspaceRole::Guest->value && $projectIds === []) {
            $validator->errors()->add('project_ids', __('validation.invitation.guest_requires_projects'));
        }
    }

    /**
     * @param  array{email: string, role?: string, project_ids?: array<int, string>}  $data
     */
    public function handle(Workspace $workspace, array $data): Invitation
    {
        $role = $data['role'] ?? WorkspaceRole::Member->value;
        $projectIds = array_values($data['project_ids'] ?? []);

        // Object-level mirror of the HTTP cross-field rule: protects every
        // call site (web, MCP, tests) from a scope-less guest invitation.
        if ($role === WorkspaceRole::Guest->value && $projectIds === []) {
            throw ValidationException::withMessages([
                'project_ids' => __('validation.invitation.guest_requires_projects'),
            ]);
        }

        return DB::transaction(function () use ($workspace, $data, $projectIds, $role): Invitation {
            $invitation = Invitation::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'email' => $data['email'],
                'role' => $role,
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7),
            ]);

            // Guest and member invitations alike announce their perimeter:
            // never empty for a guest, optional for a member (empty = the
            // default full workspace access).
            if ($projectIds !== []) {
                $invitation->attachScope($projectIds);
            }

            return $invitation;
        });
    }

    public function asController(ActionRequest $request, Workspace $workspace, InviteMemberResponder $responder): RedirectResponse
    {
        $invitation = $this->handle($workspace, $request->validated());

        return $responder->toResponse($request, $invitation);
    }
}
