<?php

namespace App\Actions\Auth;

use App\Responders\Auth\LoginPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowLoginPage
{
    use AsController;
    use AsObject;

    public function handle(): array
    {
        return [];
    }

    public function asController(Request $request, LoginPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle());
    }
}
