<?php

namespace App\Http\Middleware;

use App\Actions\TimeTracking\ShowActiveTimers;
use App\Models\ActiveTimer;
use App\Models\CommentMention;
use App\Models\Project;
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
            // Instance feature flags (config/taskr.php): what this
            // deployment chose to expose. The nav renders accordingly,
            // and nothing leaks a hidden section behind a clickable link.
            'features' => [
                'chat' => (bool) config('taskr.features.chat'),
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
            // Lazy: counted when the response renders, so comment mutations
            // that redirect never pay for the query. Scoped to the current
            // workspace and to the account's reachable perimeter.
            'unreadMentions' => fn (): int => $user !== null && $workspace !== null
                ? CommentMention::query()
                    ->forAccount($user)
                    ->whereNull('read_at')
                    ->count()
                : 0,
            // Lazy: the current user's running timers in this workspace,
            // oldest first. Rehydrated on every navigation — the server
            // timestamp is the source of truth, closing the browser never
            // stops a timer. Several timers may run in parallel, one per
            // issue.
            'timers' => fn (): array => $user !== null && $workspace !== null
                ? app(ShowActiveTimers::class)
                    ->handle($user)
                    ->map(fn (ActiveTimer $timer) => $timer->timerState())
                    ->values()
                    ->all()
                : [],
            // Autocomplete source of the comment editor: members and
            // projects of the workspace, within the account's perimeter.
            // Deferred: the payload rides a partial reload after the first
            // paint, so no page load pays for it on the critical path.
            'mentionables' => Inertia::defer(fn (): array => $user !== null && $workspace !== null
                ? MentionPresenter::mentionables($workspace, $user)
                : []),
            // Lazy: the workspace's active projects within the account's
            // perimeter, feeding the sidebar sub-list under the
            // "Projects" nav entry. Resolved when the response renders,
            // so every navigation carries the fresh list.
            'navProjects' => fn (): array => $user !== null && $workspace !== null
                ? $this->navProjects($workspace, $user)
                : [],
        ];
    }

    /**
     * Sidebar sub-list payload: active projects of the workspace within
     * the account's perimeter (a guest only sees its roster), ordered
     * by name like the projects page.
     *
     * @return list<array{id: string, name: string}>
     */
    private function navProjects(Workspace $workspace, User $user): array
    {
        $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);

        return $workspace->projects()
            ->active()
            ->when($scope !== null, fn ($query) => $query->whereIn('id', $scope))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name])
            ->values()
            ->all();
    }
}
