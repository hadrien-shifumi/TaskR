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

class UpdateStatus
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    public function rules(): array
    {
        $status = request()->route('status');
        $workspaceId = $this->currentWorkspace()?->id;

        $unique = Rule::unique('statuses', 'name')
            ->where('workspace_id', $workspaceId);

        if ($status instanceof Status) {
            $unique = $unique->ignore($status->id);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'category' => ['required', 'string', 'in:backlog,in_progress,done,cancelled'],
        ];
    }

    /**
     * @param  array{name: string, category: string}  $data
     */
    public function handle(Status $status, array $data): Status
    {
        $status->update([
            'name' => $data['name'],
            'category' => $data['category'],
        ]);

        return $status->refresh();
    }

    public function asController(ActionRequest $request, Status $status, StatusRedirectResponder $responder): RedirectResponse
    {
        $status = $this->handle($status, $request->validated());

        return $responder->updated($status);
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
