<?php

namespace App\Actions\Statuses;

use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Statuses\StatusRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateStatus
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    public function rules(): array
    {
        $workspaceId = $this->currentWorkspace()?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('statuses', 'name')->where('workspace_id', $workspaceId)],
            'category' => ['required', 'string', 'in:backlog,in_progress,done,cancelled'],
        ];
    }

    /**
     * @param  array{name: string, category: string}  $data
     */
    public function handle(Workspace $workspace, array $data): Status
    {
        return Status::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'position' => (int) $workspace->statuses()->max('position') + 1,
        ]);
    }

    public function asController(ActionRequest $request, StatusRedirectResponder $responder): RedirectResponse
    {
        $status = $this->handle($this->currentWorkspace(), $request->validated());

        return $responder->created($status);
    }

    protected function currentUserIsOwner(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
