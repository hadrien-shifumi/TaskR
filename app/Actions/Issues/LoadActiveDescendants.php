<?php

namespace App\Actions\Issues;

use App\Models\Issue;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Shared loader of an issue's active descendants: every non-archived
 * descendant, all generations, with its status — one query per depth
 * level, never one per issue. A guest only keeps the subtrees of their
 * perimeter: an out-of-scope descendant is dropped with everything below
 * it, like the invisible thing it is for them.
 *
 * The compact cards (list rows, kanban strips, project board) and the
 * full fiche share the same semantics, so a screen never offers a cascade
 * the server would refuse — and the move action enforces its guard on the
 * very same set.
 */
class LoadActiveDescendants
{
    use AsObject;

    /**
     * @param  Collection<int, Issue>  $issues  The roots (same workspace)
     * @return Collection<int, Issue> Flat, each model carrying its status
     */
    public function run(Collection $issues, ?User $user = null): Collection
    {
        if ($issues->isEmpty()) {
            return collect();
        }

        $scope = $user instanceof User
            ? app(WorkspaceContext::class)->accessibleProjectIds($user)
            : null;

        $all = collect();
        $fetched = collect();
        $frontier = $issues->pluck('id');

        while ($frontier->isNotEmpty()) {
            $level = (new Issue)
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $issues->first()->workspace_id)
                ->whereNull('archived_at')
                ->whereIn('parent_id', $frontier)
                ->with('status')
                ->get();

            if ($scope !== null) {
                $level = $level->filter(fn (Issue $descendant) => in_array($descendant->project_id, $scope, true));
            }

            // The roots may overlap (an issue and its own child listed
            // together): a node whose parent sits at several levels would
            // otherwise be fetched twice and counted twice.
            $level = $level->reject(fn (Issue $descendant) => $fetched->contains($descendant->getKey()))->values();

            $all = $all->merge($level);
            $fetched = $fetched->merge($level->pluck('id'));
            $frontier = $level->pluck('id');
        }

        return $all->values();
    }
}
