<?php

namespace App\Responders\Workspaces;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UpdateMemberRoleResponder
{
    public function updated(User $member): RedirectResponse
    {
        return redirect()
            ->route('settings.members')
            ->with('status', __('flash.member.role_updated', ['name' => $member->name]));
    }
}
