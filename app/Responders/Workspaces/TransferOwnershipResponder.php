<?php

namespace App\Responders\Workspaces;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TransferOwnershipResponder
{
    public function transferred(User $newOwner): RedirectResponse
    {
        return redirect()
            ->route('settings.members')
            ->with('status', __('flash.workspace.ownership_transferred', ['name' => $newOwner->name]));
    }
}
