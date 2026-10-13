<?php

namespace App\Actions\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class LogoutUser
{
    use AsController;
    use AsObject;

    public function handle(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function asController(Request $request): RedirectResponse
    {
        $this->handle($request);

        return redirect()->route('login');
    }
}
