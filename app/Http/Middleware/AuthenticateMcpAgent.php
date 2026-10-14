<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless authentication for the MCP endpoint: a Sanctum personal access
 * token presented as a bearer token authenticates the agent as its owning
 * user. No session, no cookies.
 */
class AuthenticateMcpAgent
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            $accessToken = PersonalAccessToken::findToken($bearer);

            if ($accessToken !== null) {
                $user = $accessToken->tokenable;

                if ($user instanceof User) {
                    auth()->setUser($user);

                    return $next($request);
                }
            }
        }

        abort(401, 'Unauthenticated.');
    }
}
