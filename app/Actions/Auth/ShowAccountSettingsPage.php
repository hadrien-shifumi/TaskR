<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Responders\Auth\AccountSettingsPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ShowAccountSettingsPage
{
    use AsController;

    /**
     * Renders the personal account settings (password change). No workspace
     * data is needed: the page is purely personal, like the locale switcher.
     */
    public function asController(Request $request, AccountSettingsPageResponder $responder): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return $responder->toResponse($request, $user);
    }
}
