<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ActiveTimer;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ShowActiveTimers
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Every running timer of the current user in this workspace, oldest
     * first — the parallel-timers state served to the widget and pages.
     *
     * @return Collection<int, ActiveTimer>
     */
    public function handle(User $user): Collection
    {
        return ActiveTimer::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->with('issue')
            ->orderBy('started_at')
            ->get();
    }

    public function asController(ActionRequest $request)
    {
        $timers = $this->handle($request->user());

        return response()->json([
            'timers' => $timers
                ->map(fn (ActiveTimer $timer) => $timer->timerState())
                ->values()
                ->all(),
        ]);
    }
}
