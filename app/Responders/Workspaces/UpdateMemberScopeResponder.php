<?php

namespace App\Responders\Workspaces;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UpdateMemberScopeResponder
{
    public function updated(User $member): RedirectResponse
    {
        return redirect()
            ->route('settings.members')
            ->with('status', __('flash.member.scope_updated', ['name' => $member->name]));
    }
}
