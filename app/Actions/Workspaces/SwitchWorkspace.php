<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SwitchWorkspace
{
    use AsController;
    use AsObject;

    public function handle(User $user, Workspace $workspace): Workspace
    {
        if (! $workspace->hasMember($user)) {
            throw new AuthorizationException('You are not a member of this workspace.');
        }

        session(['workspace_id' => $workspace->id]);

        // Account-wide mirror read by background jobs: the session alone
        // is invisible to the queue worker that sends the digest.
        $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();

        return $workspace;
    }

    public function asController(Workspace $workspace): RedirectResponse
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $this->handle($user, $workspace);

        return redirect()->intended(route('home', absolute: false));
    }
}
