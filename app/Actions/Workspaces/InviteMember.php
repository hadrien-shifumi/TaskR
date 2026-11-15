<?php

namespace App\Actions\Workspaces;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\InvitationProject;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Workspaces\InviteMemberResponder;
use App\Support\InvitationMailer;
use App\Support\WorkspaceContext;
use Closure;
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

    /**
     * Project roles an invitation may announce. The project ownership
     * never rides an invitation: it transfers through the explicit
     * action, once the invitee has arrived.
     *
     * @return array<int, string>
     */
    public static function invitableProjectRoles(): array
    {
        return array_map(
            static fn (ProjectRole $role): string => $role->value,
            array_filter(ProjectRole::cases(), static fn (ProjectRole $role): bool => $role !== ProjectRole::Owner),
        );
    }

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
            // An address already belonging to a member of this workspace is
            // refused up front: inviting them again would be meaningless (the
            // acceptance never downgrades an existing membership).
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($workspace): void {
                    if (self::emailBelongsToMember($workspace, (string) $value)) {
                        $fail(__('validation.invitation.already_member'));
                    }
                },
            ],
            'role' => ['nullable', 'string', 'in:'.implode(',', array_column(WorkspaceRole::cases(), 'value'))],
            // D16: each announced project carries its own project role.
            // The inviter is always the workspace owner (authorize above),
            // so no N+1 rank constraint applies: any invitable role fits.
            'projects' => ['nullable', 'array'],
            'projects.*.project_id' => [
                'required',
                'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id),
            ],
            'projects.*.role' => [
                'nullable',
                'string',
                'in:'.implode(',', self::invitableProjectRoles()),
            ],
            // Legacy shape, kept for callers without per-project roles:
            // every id lands as a plain member.
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => [
                'ulid',
                Rule::exists('projects', 'id')->where('workspace_id', $workspace?->id),
            ],
        ];
    }

    /**
     * Cross-field rule the base validator cannot express: a guest always
     * carries a non-empty perimeter. Runs as an "after" hook — errors
     * added before the run are wiped by the validator.
     */
    public function afterValidator(Validator $validator): void
    {
        $data = $validator->getData();
        $role = ($data['role'] ?? null) ?: WorkspaceRole::Member->value;

        if ($role === WorkspaceRole::Guest->value && self::normalizeProjects($data) === []) {
            $validator->errors()->add('projects', __('validation.invitation.guest_requires_projects'));
        }
    }

    /**
     * Merge the two accepted input shapes into the canonical list of
     * announced projects: explicit "projects" entries (with their role,
     * defaulting to member) win over the legacy "project_ids" ids, and
     * a duplicated project keeps the last role announced.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{project_id: string, role: string}>
     */
    public static function normalizeProjects(array $data): array
    {
        $projects = [];

        foreach (array_values($data['project_ids'] ?? []) as $projectId) {
            $projectId = (string) $projectId;
            $projects[$projectId] = [
                'project_id' => $projectId,
                'role' => ProjectRole::Member->value,
            ];
        }

        foreach (array_values($data['projects'] ?? []) as $project) {
            if (! is_array($project)) {
                continue;
            }

            $projectId = (string) ($project['project_id'] ?? '');
            $projects[$projectId] = [
                'project_id' => $projectId,
                'role' => (($project['role'] ?? null) !== null && $project['role'] !== '')
                    ? (string) $project['role']
                    : ProjectRole::Member->value,
            ];
        }

        return array_values($projects);
    }

    /**
     * @param  array{email: string, role?: string, project_ids?: array<int, string>, projects?: array<int, array{project_id: string, role?: string}>}  $data
     */
    public function handle(Workspace $workspace, array $data): Invitation
    {
        $role = $data['role'] ?? WorkspaceRole::Member->value;
        $projects = self::normalizeProjects($data);

        // Object-level mirror of the HTTP cross-field rule: protects every
        // call site (web, MCP, tests) from a perimeter-less guest invitation.
        if ($role === WorkspaceRole::Guest->value && $projects === []) {
            throw ValidationException::withMessages([
                'projects' => __('validation.invitation.guest_requires_projects'),
            ]);
        }

        // Object-level mirror of the HTTP "already a member" rule.
        if (self::emailBelongsToMember($workspace, $data['email'])) {
            throw ValidationException::withMessages([
                'email' => __('validation.invitation.already_member'),
            ]);
        }

        $invitation = DB::transaction(function () use ($workspace, $data, $projects, $role): Invitation {
            // One invitation row per (workspace, email) — the unique
            // constraint enforces it at the database level. Re-inviting an
            // existing address (pending, expired, or accepted by a member
            // since removed) reactivates that row instead of crashing on
            // the constraint: fresh token and expiry, the consumed
            // acceptance is reset, and the latest announced projects and
            // roles win.
            $invitation = Invitation::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->whereRaw('lower(email) = lower(?)', [$data['email']])
                ->first();

            if ($invitation !== null) {
                $invitation->update([
                    'role' => $role,
                    'token' => Str::random(64),
                    'expires_at' => now()->addDays(7),
                    'accepted_at' => null,
                ]);

                InvitationProject::query()
                    ->where('invitation_id', $invitation->getKey())
                    ->delete();

                if ($projects !== []) {
                    $invitation->attachProjects($projects);
                }

                return $invitation;
            }

            $invitation = Invitation::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'email' => $data['email'],
                'role' => $role,
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7),
            ]);

            // Guest and member invitations alike announce their projects:
            // never empty for a guest, optional for a member (empty = the
            // member joins no team and sees no project).
            if ($projects !== []) {
                $invitation->attachProjects($projects);
            }

            return $invitation;
        });

        // Sent after the transaction commits: a rolled-back invitation must
        // never reach the invitee's inbox. Covers creation and reactivation
        // alike — the mail always carries the fresh acceptance link.
        InvitationMailer::send($invitation);

        return $invitation;
    }

    public function asController(ActionRequest $request, Workspace $workspace, InviteMemberResponder $responder): RedirectResponse
    {
        $invitation = $this->handle($workspace, $request->validated());

        return $responder->toResponse($request, $invitation, $invitation->wasRecentlyCreated);
    }

    /**
     * Whether the address already belongs to a member of the workspace
     * (case-insensitive, like the invitation acceptance check).
     */
    private static function emailBelongsToMember(?Workspace $workspace, string $email): bool
    {
        if ($workspace === null) {
            return false;
        }

        return Membership::query()
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.workspace_id', $workspace->id)
            ->whereRaw('lower(users.email) = lower(?)', [$email])
            ->exists();
    }
}
