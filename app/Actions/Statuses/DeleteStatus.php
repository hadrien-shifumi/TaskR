<?php

namespace App\Actions\Statuses;

use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Statuses\StatusRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteStatus
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    public function handle(Status $status): Status
    {
        $usage = $status->issuesCount();

        if ($usage > 0) {
            abort(409, "This status is used by {$usage} issue(s). Move them to another status first.");
        }

        $status->delete();

        return $status;
    }

    public function asController(Status $status, StatusRedirectResponder $responder): RedirectResponse
    {
        $this->handle($status);

        return $responder->deleted();
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
