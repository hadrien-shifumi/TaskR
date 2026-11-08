<?php

namespace App\Responders\Milestones;

use App\Models\Milestone;
use Illuminate\Http\RedirectResponse;

class MilestoneRedirectResponder
{
    /**
     * There is no dedicated milestone page (the milestone surfaces live
     * on the project screens): every mutation returns to the caller's
     * context so the calling screen reflects the change.
     */
    public function created(Milestone $milestone): RedirectResponse
    {
        return redirect()
            ->back()
            ->with('status', __('flash.milestone.created'));
    }

    public function updated(Milestone $milestone): RedirectResponse
    {
        return redirect()
            ->back()
            ->with('status', __('flash.milestone.updated'));
    }

    public function closed(Milestone $milestone): RedirectResponse
    {
        return redirect()
            ->back()
            ->with('status', __('flash.milestone.closed'));
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->back()
            ->with('status', __('flash.milestone.deleted'));
    }
}
