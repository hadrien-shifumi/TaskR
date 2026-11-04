<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Responders\Auth\PasswordRedirectResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdatePassword
{
    use AsController;
    use AsObject;

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ];
    }

    /**
     * Changes the account password after verifying the current one. Unlike
     * the forgot-password reset, sessions and API tokens are preserved: the
     * user stays logged in and programmatic access keeps working.
     */
    public function handle(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => trans('validation.password.wrong_current'),
            ]);
        }

        // The `hashed` cast hashes the plain value on save.
        $user->fill(['password' => $newPassword])->save();
    }

    public function asController(ActionRequest $request, PasswordRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $this->handle($user, $request->validated('current_password'), $request->validated('password'));

        return $responder->updated();
    }
}
