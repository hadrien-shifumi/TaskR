<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\ActiveTimer;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Validation\ValidationException;
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
     * Stop one of the user's running timers in this workspace and return
     * the pre-filled standard-entry proposal. Nothing is recorded here: the
     * confirmation goes through the ordinary time-entry flow.
     *
     * With several timers running, the target must be designated by its id;
     * without a target, the stop only succeeds while a lone timer runs.
     *
     * @param  User  $user  the acting user
     * @param  string|null  $timerId  the timer to stop, null to stop the only running one
     * @return array<int, array<string, mixed>> the stopped timer's proposal, as a one-element list
     *
     * @throws ValidationException when no target is given and several timers run
     */
    public function handle(User $user, ?string $timerId = null): array
    {
        $query = ActiveTimer::query()
            ->where('user_id', $user->getKey())
            ->where('workspace_id', app(WorkspaceContext::class)->id())
            ->with('issue');

        if ($timerId !== null) {
            $timer = (clone $query)->where('id', $timerId)->first();

            abort_if($timer === null, 404, 'No active timer.');

            $proposal = $timer->stoppedProposal();
            $timer->delete();

            return [$proposal];
        }

        $timers = $query->get();

        if ($timers->count() > 1) {
            throw ValidationException::withMessages([
                'timer' => [__('validation.timer.stop_target_required')],
            ]);
        }

        $timer = $timers->first();

        abort_if($timer === null, 404, 'No active timer.');

        $proposal = $timer->stoppedProposal();
        $timer->delete();

        return [$proposal];
    }

    public function asController(ActionRequest $request)
    {
        $request->validate([
            'timer_id' => ['nullable', 'ulid'],
        ]);

        $stopped = $this->handle($request->user(), $request->input('timer_id'));

        return response()->json(['stopped' => $stopped]);
    }
}
