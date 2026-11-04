<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ResetPassword
{
    use AsController;
    use AsObject;

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ];
    }

    /**
     * @param  array{token: string, email: string, password: string}  $data
     */
    public function handle(array $data): void
    {
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // Every open session of the account dies with the old password:
            // stolen sessions cannot survive a reset.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => trans($status),
            ]);
        }
    }

    public function asController(ActionRequest $request): RedirectResponse
    {
        $this->handle($request->validated());

        return redirect()
            ->route('login')
            ->with('status', __('flash.password.reset_done'));
    }
}
