<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        Inertia::share($this->share($request));

        return $next($request);
    }

    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        /** @var Workspace|null $workspace */
        $workspace = app(WorkspaceContext::class)->get();

        return [
            'flash' => [
                'status' => $request->session()->get('status'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
            ],
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $workspace->roleOf($user)?->value,
            ] : null,
            'workspaces' => $user
                ? $user->memberships()
                    ->with('workspace')
                    ->get()
                    ->map(fn ($membership) => [
                        'id' => $membership->workspace->id,
                        'name' => $membership->workspace->name,
                        'role' => $membership->role->value,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
