<?php

namespace App\Responders\TimeTracking;

use App\Models\Issue;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;

class TimeTrackingResponder
{
    public function estimateSet(Issue $issue): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $issue->id)
            ->with('status', 'Estimate updated.');
    }

    public function entryCreated(TimeEntry $timeEntry): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $timeEntry->issue_id)
            ->with('status', 'Time entry added.');
    }

    public function entryUpdated(TimeEntry $timeEntry): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $timeEntry->issue_id)
            ->with('status', 'Time entry updated.');
    }

    public function entryDeleted(TimeEntry $timeEntry): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $timeEntry->issue_id)
            ->with('status', 'Time entry deleted.');
    }
}
