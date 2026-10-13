<?php

namespace App\Actions\Auth;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Responders\Auth\RegisterResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class RegisterUser
{
    use AsController;
    use AsObject;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'workspace_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string, workspace_name?: string|null}  $data
     */
    public function handle(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        CreateWorkspace::run($user, [
            'name' => $data['workspace_name'] ?? "{$data['name']}'s Workspace",
        ]);

        return $user;
    }

    public function asController(Request $request, RegisterResponder $responder): RedirectResponse
    {
        $user = $this->handle($request->all());

        Auth::login($user);
        $request->session()->regenerate();

        return $responder->toResponse($request, $user);
    }
}
