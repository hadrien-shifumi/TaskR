<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateProject
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('projects', 'name')->where('workspace_id', $this->currentWorkspace()?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Create a project and auto-onboard the creator as project owner.
     *
     * @param  array{name: string, description?: string|null, target_date?: string|null}  $data
     */
    public function handle(Workspace $workspace, User $creator, array $data): Project
    {
        // A guest can never be a project owner: the roster pilotage is
        // owner-of-the-workspace only, so guests do not create projects.
        if (app(WorkspaceContext::class)->id() === $workspace->id
            && $workspace->isGuest($creator)) {
            throw new AuthorizationException('Guests cannot create projects.');
        }

        return DB::transaction(function () use ($creator, $data): Project {
            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'target_date' => $data['target_date'] ?? null,
            ]);

            $project->projectMembers()->create([
                'user_id' => $creator->getKey(),
                'role' => ProjectRole::Owner->value,
            ]);

            $this->recordCreation($project, $creator);

            return $project;
        });
    }

    /**
     * Journal the creation with the created fields (before null). Empty
     * optional fields (missing description or target date) stay out of
     * the diff to keep the event light.
     */
    private function recordCreation(Project $project, User $creator): void
    {
        $changes = [
            'name' => ['before' => null, 'after' => $project->name],
        ];

        if ($project->description !== null) {
            $changes['description'] = ['before' => null, 'after' => $project->description];
        }

        if ($project->target_date !== null) {
            $changes['target_date'] = ['before' => null, 'after' => $project->target_date->toDateString()];
        }

        RecordActivity::run(AuditEventType::ProjectCreated, $project, $creator, $changes);
    }

    public function asController(ActionRequest $request, ProjectRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $project = $this->handle($this->currentWorkspace(), $user, $request->validated());

        return $responder->created($project);
    }
}
