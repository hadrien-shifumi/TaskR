<?php

namespace App\Actions\Auth;

use App\Responders\Auth\ForgotPasswordPageResponder;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowForgotPasswordPage
{
    use AsController;
    use AsObject;

    public function handle(): array
    {
        return [];
    }

    public function asController(ActionRequest $request, ForgotPasswordPageResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle());
    }
}
