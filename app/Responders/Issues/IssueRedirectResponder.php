<?php

namespace App\Responders\Issues;

use App\Models\Issue;
use Illuminate\Http\RedirectResponse;

class IssueRedirectResponder
{
    public function created(Issue $issue): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $issue)
            ->with('status', __('flash.issue.created'));
    }

    public function updated(Issue $issue): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $issue)
            ->with('status', __('flash.issue.updated'));
    }

    /**
     * Moves come from either the issue page or the kanban board: stay in the
     * caller's context so the calling screen reflects the new status.
     */
    public function moved(Issue $issue): RedirectResponse
    {
        return redirect()
            ->back()
            ->with('status', __('flash.issue.moved'));
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('issues.index')
            ->with('status', __('flash.issue.deleted'));
    }
}
