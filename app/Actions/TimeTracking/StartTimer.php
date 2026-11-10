<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\StatusCategory;
use App\Models\ActiveTimer;
use App\Models\Issue;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class StartTimer
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * Start a timer on the given issue. Timers now run in parallel: nothing
     * is stopped unless the caller explicitly asks for it, and the stopped
     * timers' proposals are returned so their elapsed time is never lost —
     * recording it stays the user's call. One timer per issue per user.
     *
     * @param  Issue  $issue  the issue to time (route binding, workspace-scoped)
     * @param  User  $user  the acting user
     * @param  bool  $stopPrevious  stop every other running timer of this user first
     * @return array{timer: ActiveTimer, stopped: array<int, array<string, mixed>>}
     *
     * @throws ValidationException when the issue is done or cancelled, or already timed by this user
     */
    public function handle(Issue $issue, User $user, bool $stopPrevious = false): array
    {
        app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);

        if (in_array($issue->status?->category, [StatusCategory::Done, StatusCategory::Cancelled], true)) {
            throw ValidationException::withMessages([
                'issue' => [__('validation.timer.issue_closed')],
            ]);
        }

        return DB::transaction(function () use ($issue, $user, $stopPrevious): array {
            $alreadyTimed = ActiveTimer::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', app(WorkspaceContext::class)->id())
                ->where('issue_id', $issue->getKey())
                ->exists();

            if ($alreadyTimed) {
                throw ValidationException::withMessages([
                    'issue' => [__('validation.timer.already_running')],
                ]);
            }

            $stopped = [];

            if ($stopPrevious) {
                // Oldest first, so the confirmation dialogs queue in the
                // order the timers were started.
                $previous = ActiveTimer::query()
                    ->where('user_id', $user->getKey())
                    ->where('workspace_id', app(WorkspaceContext::class)->id())
                    ->with('issue')
                    ->orderBy('started_at')
                    ->get();

                foreach ($previous as $timer) {
                    $stopped[] = $timer->stoppedProposal();
                    $timer->delete();
                }
            }

            $timer = ActiveTimer::create([
                'workspace_id' => app(WorkspaceContext::class)->id(),
                'user_id' => $user->getKey(),
                'issue_id' => $issue->getKey(),
                'started_at' => now(),
            ]);

            return ['timer' => $timer, 'stopped' => $stopped];
        });
    }

    public function asController(ActionRequest $request, Issue $issue)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        ['timer' => $timer, 'stopped' => $stopped] = $this->handle(
            $issue,
            $user,
            $request->boolean('stop_previous'),
        );

        return response()->json([
            'timer' => $timer->timerState(),
            'stopped' => $stopped,
        ]);
    }
}
