<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Projects\ProjectRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateProject
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsMember();
    }

    public function rules(): array
    {
        $project = request()->route('project');

        $unique = Rule::unique('projects', 'name')
            ->where('workspace_id', $this->currentWorkspace()?->id);

        if ($project instanceof Project) {
            $unique = $unique->ignore($project->id);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(Project $project, array $data): Project
    {
        $project->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return $project->refresh();
    }

    public function asController(ActionRequest $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $project = $this->handle($project, $request->validated());

        return $responder->updated($project);
    }

    protected function currentUserIsMember(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
