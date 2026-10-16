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
            ->with('status', __('flash.status.created', ['name' => $status->name]));
    }

    public function updated(?Status $status): RedirectResponse
    {
        return redirect()
            ->route('settings.statuses')
            ->with('status', __('flash.status.updated'));
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('settings.statuses')
            ->with('status', __('flash.status.deleted'));
    }
}
