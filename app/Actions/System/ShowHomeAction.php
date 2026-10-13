<?php

namespace App\Actions\System;

use App\Responders\System\HomeResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ShowHomeAction
{
    use AsController;
    use AsObject;

    /**
     * @return array{appName: string}
     */
    public function handle(): array
    {
        return [
            'appName' => (string) config('app.name'),
        ];
    }

    public function asController(Request $request, HomeResponder $responder): Response
    {
        return $responder->toResponse($request, $this->handle());
    }
}
