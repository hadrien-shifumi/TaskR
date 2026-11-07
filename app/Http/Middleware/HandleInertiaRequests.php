<?php

namespace App\Http\Middleware;

use App\Models\CommentMention;
use App\Models\User;
use App\Models\Workspace;
use App\Presenters\Mentions\MentionPresenter;
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
            'errors' => fn () => $request->session()->get('errors')
                ? $request->session()->get('errors')->getBag('default')->getMessages()
                : (object) [],
            'flash' => [
                'status' => $request->session()->get('status'),
                // Security-sensitive one-shot value: consumed on first render,
                // never exposed again.
                'newToken' => $request->session()->pull('newToken'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                ] : null,
            ],
            // Lazy: resolved when the response is rendered, so a request that
            // changes the locale (UpdateUserLocale) returns the NEW locale if
            // its response ever renders props directly instead of redirecting.
            'locale' => fn () => app()->getLocale(),
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
            // Lazy: counted when the response renders, so comment mutations
            // that redirect never pay for the query. Scoped to the current
            // workspace and to the account's reachable perimeter.
            'unreadMentions' => fn (): int => $user !== null && $workspace !== null
                ? CommentMention::query()
                    ->forAccount($user)
                    ->whereNull('read_at')
                    ->count()
                : 0,
            // Autocomplete source of the comment editor: members and
            // projects of the workspace, within the account's perimeter.
            // Deferred: the payload rides a partial reload after the first
            // paint, so no page load pays for it on the critical path.
            'mentionables' => Inertia::defer(fn (): array => $user !== null && $workspace !== null
                ? MentionPresenter::mentionables($workspace, $user)
                : []),
        ];
    }
}
