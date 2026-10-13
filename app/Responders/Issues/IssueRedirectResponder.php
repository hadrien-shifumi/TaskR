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
            ->with('status', 'Issue created.');
    }

    public function updated(Issue $issue): RedirectResponse
    {
        return redirect()
            ->route('issues.show', $issue)
            ->with('status', 'Issue updated.');
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('issues.index')
            ->with('status', 'Issue deleted.');
    }
}
