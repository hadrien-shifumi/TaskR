<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Responders\Auth\LoginResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class LoginUser
{
    use AsController;
    use AsObject;

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array{email: string, password: string}  $data
     */
    public function handle(array $data): User
    {
        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Auth::validate(['email' => $data['email'], 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        return $user;
    }

    public function asController(Request $request, LoginResponder $responder): RedirectResponse
    {
        $user = $this->handle($request->all());

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return $responder->toResponse($request, $user);
    }
}
