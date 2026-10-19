<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Sanctum\NewAccessToken;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateApiToken
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsFullMember();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Generates a personal access token. The plain-text value is only
     * available on the returned NewAccessToken: it is never persisted.
     *
     * @param  array{name: string}  $data
     */
    public function handle(User $user, array $data): NewAccessToken
    {
        return $user->createToken($data['name']);
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $newToken = $this->handle($user, $request->validated());

        return redirect()
            ->route('settings.tokens')
            ->with('status', __('flash.token.created'))
            ->with('newToken', $newToken->plainTextToken);
    }
}
