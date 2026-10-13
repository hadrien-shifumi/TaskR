<?php

namespace App\Responders\Statuses;

use App\Models\Status;
use Illuminate\Http\RedirectResponse;

class StatusRedirectResponder
{
    public function created(Status $status): RedirectResponse
    {
        return redirect()
            ->route('settings.statuses')
            ->with('status', "Status \"{$status->name}\" created.");
    }

    public function updated(?Status $status): RedirectResponse
    {
        return redirect()
            ->route('settings.statuses')
            ->with('status', 'Statuses updated.');
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('settings.statuses')
            ->with('status', 'Status deleted.');
    }
}
