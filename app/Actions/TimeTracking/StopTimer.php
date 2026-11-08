<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ActiveTimer;
use App\Models\User;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class StopTimer
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Stop the user's running timer in this workspace and return the
     * pre-filled standard-entry proposal. Nothing is recorded here: the
     * confirmation goes through the ordinary time-entry flow.
     *
     * @return array<string, mixed>|null null when no timer is running
     */
    public function handle(User $user): ?array
    {
        $timer = ActiveTimer::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->first();

        if ($timer === null) {
            return null;
        }

        $proposal = $timer->stoppedProposal();

        $timer->delete();

        return $proposal;
    }

    public function asController(ActionRequest $request)
    {
        $proposal = $this->handle($request->user());

        abort_if($proposal === null, 404, 'No active timer.');

        return response()->json(['stopped' => $proposal]);
    }
}
