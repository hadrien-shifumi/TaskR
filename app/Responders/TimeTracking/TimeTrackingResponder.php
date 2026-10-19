<?php

namespace App\Responders\TimeTracking;

use App\Models\Issue;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;

class TimeTrackingResponder
{
    /**
     * Time mutations come from the full issue page or from the board's issue
     * modal (?issue= param): stay in the caller's context so the modal never
     * drops the user back to the bare issue page.
     */
    private function back(string $status): RedirectResponse
    {
        return redirect()->back()->with('status', $status);
    }

    public function estimateSet(Issue $issue): RedirectResponse
    {
        return $this->back(__('flash.estimate.updated'));
    }

    public function entryCreated(TimeEntry $timeEntry): RedirectResponse
    {
        return $this->back(__('flash.time_entry.added'));
    }

    public function entryUpdated(TimeEntry $timeEntry): RedirectResponse
    {
        return $this->back(__('flash.time_entry.updated'));
    }

    public function entryDeleted(TimeEntry $timeEntry): RedirectResponse
    {
        return $this->back(__('flash.time_entry.deleted'));
    }
}
