<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\User;
use App\Presenters\Auth\TokenPresenter;
use App\Responders\Auth\TokensPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowApiTokensPage
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsFullMember();
    }

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
                ->map(fn ($token) => TokenPresenter::present($token))
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
