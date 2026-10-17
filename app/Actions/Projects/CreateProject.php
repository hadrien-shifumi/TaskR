<?php

namespace App\Actions\Projects;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
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

            return $project;
        });
    }

    public function asController(ActionRequest $request, ProjectRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $project = $this->handle($this->currentWorkspace(), $user, $request->validated());

        return $responder->created($project);
    }

    protected function currentUserCanUseWorkspace(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->canUseWorkspace($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
