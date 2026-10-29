<?php

namespace App\Support;

use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Cycle detection over the blocked-by dependency graph.
 *
 * Unlike the parent hierarchy (HasAncestorChain, one parent per node), an
 * issue may declare several blockers: reachability is walked depth-first
 * over every issue_dependencies edge, so closing a chain of any length is
 * caught, not just a direct two-node swap. Edges are read from the pivot
 * table directly — cross-project links are legitimate, and both endpoints
 * of a stored dependency always belong to the same workspace.
 */
class IssueDependencyGraph
{
    /**
     * Safety valve mirroring HasAncestorChain::ancestorChain(): bounds the
     * walk should pre-existing corrupt data ever form a cycle behind us.
     * The visited set alone already guarantees termination.
     */
    private const MAX_DEPTH = 100;

    /**
     * Whether syncing the proposed blockers onto the issue would close a
     * cycle: a proposed blocker that can reach the issue through existing
     * blocked-by edges, or the issue itself (self-block). The issue's own
     * current edges are irrelevant — the sync replaces them wholesale, and
     * any path reaching the issue is already a cycle before expanding it.
     *
     * @param  array<int, string>  $blockedByIds
     */
    public static function wouldCreateCycle(Issue $issue, array $blockedByIds): bool
    {
        if (in_array($issue->id, $blockedByIds, true)) {
            return true;
        }

        $visited = [];
        $stack = array_values(array_unique($blockedByIds));
        $depth = 0;

        while ($stack !== [] && $depth < self::MAX_DEPTH) {
            $currentId = array_pop($stack);

            if (in_array($currentId, $visited, true)) {
                continue;
            }

            $visited[] = $currentId;
            $depth++;

            if ($currentId === $issue->id) {
                return true;
            }

            foreach (self::blockerIdsOf($currentId) as $blockerId) {
                if (! in_array($blockerId, $visited, true)) {
                    $stack[] = $blockerId;
                }
            }
        }

        return false;
    }

    /**
     * Ids of every blocker of the given issue, following the blocked-by
     * edges as stored — workspace scopes do not apply to the pivot table.
     *
     * @return array<int, string>
     */
    private static function blockerIdsOf(string $issueId): array
    {
        return DB::table('issue_dependencies')
            ->where('issue_id', $issueId)
            ->pluck('blocked_by_id')
            ->all();
    }
}
