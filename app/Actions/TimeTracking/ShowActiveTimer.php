<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ActiveTimer;
use App\Models\User;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ShowActiveTimer
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * The current user's running timer in this workspace, or null.
     */
    public function handle(User $user): ?ActiveTimer
    {
        return ActiveTimer::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->first();
    }

    public function asController(ActionRequest $request)
    {
        $timer = $this->handle($request->user());

        return response()->json([
            'timer' => $timer === null ? null : $timer->timerState(),
        ]);
    }
}
