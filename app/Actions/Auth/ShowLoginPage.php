<?php

namespace App\Actions\Auth;

use App\Responders\Auth\LoginPageResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

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
