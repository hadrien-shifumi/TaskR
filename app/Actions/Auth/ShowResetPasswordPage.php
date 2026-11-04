<?php

namespace App\Actions\Auth;

use App\Responders\Auth\ResetPasswordPageResponder;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowResetPasswordPage
{
    use AsController;
    use AsObject;

    /**
     * @return array{token: string, email: string}
     */
    public function handle(string $token, string $email = ''): array
    {
        return [
            'token' => $token,
            'email' => $email,
        ];
    }

    public function asController(ActionRequest $request, ResetPasswordPageResponder $responder): Response
    {
        /** @var string $token */
        $token = $request->route('token');

        return $responder->toResponse($request, $this->handle($token, (string) $request->query('email', '')));
    }
}
