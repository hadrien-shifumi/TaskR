<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Responders\Auth\TokensPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowApiTokensPage
{
    use AsController;
    use AsObject;

    /**
     * Lists the personal access tokens of the given user (name and dates
     * only: the token value is never stored in clear).
     *
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        return [
            'tokens' => $user->tokens()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'createdAt' => $token->created_at->toISOString(),
                    'lastUsedAt' => $token->last_used_at?->toISOString(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function asController(Request $request, TokensPageResponder $responder): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return $responder->toResponse($request, $this->handle($user));
    }
}
