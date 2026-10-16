<?php

namespace App\Responders\Workspaces;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

class RemoveMemberResponder
{
    public function removed(User $member): RedirectResponse
    {
        return redirect()
            ->route('settings.members')
            ->with('status', __('flash.member.removed', ['name' => $member->name]));
    }
}
