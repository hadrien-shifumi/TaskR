<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordActivity;
use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\AuditEventType;
use App\Models\Project;
use App\Models\User;
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
    use AuthorizesWorkspaceAccess;

    protected ?Project $projectForRules = null;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Sets the project whose name must be ignored by the uniqueness rule,
     * for callers that validate programmatically (MCP layer) instead of
     * through the HTTP route binding.
     */
    public function forProject(Project $project): static
    {
        $this->projectForRules = $project;

        return $this;
    }

    public function rules(): array
    {
        $project = $this->projectForRules ?? request()->route('project');

        $unique = Rule::unique('projects', 'name')
            ->where('workspace_id', $this->currentWorkspace()?->id);

        if ($project instanceof Project) {
            $unique = $unique->ignore($project->id);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'description' => ['nullable', 'string', 'max:5000'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array{name: string, description?: string|null, target_date?: string|null}  $data
     */
    public function handle(Project $project, array $data, ?User $user = null): Project
    {
        // Out-of-scope projects fail exactly like non-existent ones; a
        // project of the perimeter stays editable by its guests.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user instanceof User) {
            app(WorkspaceContext::class)->abortUnlessProjectAccessible($user, $project);
        }

        $before = [
            'name' => $project->name,
            'description' => $project->description,
            'target_date' => $project->target_date?->toDateString(),
        ];

        $project->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'target_date' => $data['target_date'] ?? null,
        ]);

        // A no-op update (same values) journals nothing.
        $changes = [];
        foreach ($before as $field => $value) {
            $after = match ($field) {
                'target_date' => $project->target_date?->toDateString(),
                default => $project->{$field},
            };

            if ($after !== $value) {
                $changes[$field] = ['before' => $value, 'after' => $after];
            }
        }

        if ($changes !== []) {
            RecordActivity::run(AuditEventType::ProjectUpdated, $project, $user, $changes);
        }

        return $project->refresh();
    }

    public function asController(ActionRequest $request, Project $project, ProjectRedirectResponder $responder): RedirectResponse
    {
        $project = $this->handle($project, $request->validated(), $request->user());

        return $responder->updated($project);
    }
}
