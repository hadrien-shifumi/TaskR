<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentWorkspace
{
    public function __construct(protected WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // The context singleton survives across requests in long-lived
            // containers (tests, Octane): a guest request must never expose
            // a workspace left over from a previous request.
            $this->context->set(null);

            return $next($request);
        }

        $workspace = $this->resolveWorkspace($request, $user->getKey());

        $this->context->set($workspace);

        if ($workspace !== null) {
            session(['workspace_id' => $workspace->id]);
        }

        return $next($request);
    }

    /**
     * Resolve from session if still a valid membership, otherwise fall back
     * to the user's first workspace (or null when they have none).
     */
    private function resolveWorkspace(Request $request, int|string $userId): ?Workspace
    {
        $sessionWorkspaceId = $request->session()->get('workspace_id');

        $query = Workspace::query()
            ->whereHas('memberships', fn ($membership) => $membership->where('user_id', $userId));

        if ($sessionWorkspaceId !== null) {
            $workspace = (clone $query)->where('id', $sessionWorkspaceId)->first();

            if ($workspace !== null) {
                return $workspace;
            }
        }

        return (clone $query)->orderBy('name')->first();
    }
}
