<?php

namespace App\Presenters\Auth;

use Laravel\Sanctum\PersonalAccessToken;

class TokenPresenter
{
    /**
     * Row of the API tokens page — the token value itself never appears.
     *
     * @return array{id: int, name: string, createdAt: string, lastUsedAt: string|null}
     */
    public static function present(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'createdAt' => $token->created_at->toISOString(),
            'lastUsedAt' => $token->last_used_at?->toISOString(),
        ];
    }
}
