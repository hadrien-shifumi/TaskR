<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class RevokeApiToken
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isFullMember($user);
    }

    /**
     * Deletes one of the user's own tokens. Tokens owned by anyone
     * else are simply not found: no existence leak.
     */
    public function handle(User $user, string $tokenId): void
    {
        $user->tokens()->whereKey($tokenId)->firstOrFail()->delete();
    }

    public function asController(Request $request): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $tokenId = (string) $request->route('token');
        $this->handle($user, $tokenId);

        return redirect()->route('settings.tokens')->with('status', __('flash.token.revoked'));
    }
}
