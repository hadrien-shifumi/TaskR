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

class CreateProject
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsMember();
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('projects', 'name')->where('workspace_id', $this->currentWorkspace()?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(Workspace $workspace, array $data): Project
    {
        return Project::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function asController(ActionRequest $request, ProjectRedirectResponder $responder): RedirectResponse
    {
        $project = $this->handle($this->currentWorkspace(), $request->validated());

        return $responder->created($project);
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
