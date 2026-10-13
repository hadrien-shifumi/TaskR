<?php

namespace App\Actions\Auth;

use App\Responders\Auth\RegisterPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowRegistrationPage
{
    use AsController;
    use AsObject;

    public function handle(): array
    {
        return [];
    }

    public function asController(Request $request, RegisterPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle());
    }
}
