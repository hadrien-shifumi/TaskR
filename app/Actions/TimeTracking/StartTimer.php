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
     * Stop any running timer for this user in this workspace, then start
     * a fresh one on the given issue. The replaced timer's proposal is
     * returned so its elapsed time is never lost — recording it stays
     * the user's call.
     *
     * @param  Issue  $issue  the issue to time (route binding, workspace-scoped)
     * @param  User  $user  the acting user
     * @return array{timer: ActiveTimer, stopped: array<string, mixed>|null}
     *
     * @throws ValidationException when the issue is done or cancelled
     */
    public function handle(Issue $issue, User $user): array
    {
        app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);

        if (in_array($issue->status?->category, [StatusCategory::Done, StatusCategory::Cancelled], true)) {
            throw ValidationException::withMessages([
                'issue' => [__('validation.timer.issue_closed')],
            ]);
        }

        return DB::transaction(function () use ($issue, $user): array {
            $previous = ActiveTimer::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', app(WorkspaceContext::class)->id())
                ->first();

            $stopped = $previous?->stoppedProposal();

            $previous?->delete();

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

        ['timer' => $timer, 'stopped' => $stopped] = $this->handle($issue, $user);

        return response()->json([
            'timer' => $timer->timerState(),
            'stopped' => $stopped,
        ]);
    }
}
