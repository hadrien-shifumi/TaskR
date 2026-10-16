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
            ->with('status', __('flash.estimate.updated'));
    }

    public function entryCreated(TimeEntry $timeEntry): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $timeEntry->issue_id)
            ->with('status', __('flash.time_entry.added'));
    }

    public function entryUpdated(TimeEntry $timeEntry): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $timeEntry->issue_id)
            ->with('status', __('flash.time_entry.updated'));
    }

    public function entryDeleted(TimeEntry $timeEntry): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $timeEntry->issue_id)
            ->with('status', __('flash.time_entry.deleted'));
    }
}
