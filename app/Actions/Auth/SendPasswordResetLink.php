<?php

namespace App\Actions\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class SendPasswordResetLink
{
    use AsController;
    use AsObject;

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    public function handle(string $email): void
    {
        // The returned status is deliberately ignored: known and unknown
        // accounts get the exact same response, the link is only mailed
        // when the account exists.
        Password::sendResetLink(['email' => $email]);
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $this->handle($request->validated('email'));

        return redirect()
            ->route('password.request')
            ->with('status', __('flash.password.reset_link_sent'));
    }
}
