<?php

namespace App\Actions\TimeTracking;

use App\Models\Issue;
use App\Models\User;
use App\Presenters\TimeTracking\TimeEntryPresenter;
use App\Support\WorkspaceContext;
use Lorisleiva\Actions\Concerns\AsObject;

class ListTimeEntries
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Issue $issue, ?User $user = null): array
    {
        // The underlying issue must stay in the actor's perimeter: a
        // shrunken scope makes old objects fail like non-existent ones.
        // Without an explicit user this is a trusted internal object run:
        // HTTP and MCP callers always pass the actor.
        if ($user !== null) {
            app(WorkspaceContext::class)->abortUnlessIssueAccessible($user, $issue);
        }

        return $issue->timeEntries()
            ->with('author')
            ->orderByDesc('worked_at')
            ->get()
            ->map(fn ($entry) => TimeEntryPresenter::present($entry))
            ->values()
            ->all();
    }
}
