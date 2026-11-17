<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;

/**
 * Walks a self-referencing parent chain (issues, knowledge pages).
 * Requires the model to define `parent()` and a `parent_id` column.
 */
trait HasAncestorChain
{
    /**
     * Ids of every ancestor, closest parent first; used for cycle detection.
     *
     * @return array<int, string>
     */
    public function ancestorIds(): array
    {
        $ids = [];
        $current = $this;

        while ($current->parent_id !== null) {
            if (in_array($current->parent_id, $ids, true)) {
                break;
            }

            $ids[] = $current->parent_id;
            $current = $current->parent()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->firstOrFail();
        }

        return $ids;
    }

    /**
     * Ids of every descendant (children, grandchildren, …), breadth-first;
     * used to exclude a whole subtree from parent pickers so a cycle can
     * never be selected. Depth-guarded to survive pre-existing cycles.
     *
     * @return array<int, string>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];
        $depth = 0;

        while ($frontier !== [] && $depth < 50) {
            $frontier = $this->newQuery()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            // A pre-existing cycle would loop forever: only ever visit
            // ids that have not been seen yet.
            $frontier = array_values(array_diff($frontier, $ids, [$this->getKey()]));
            $ids = array_merge($ids, $frontier);
            $depth++;
        }

        return $ids;
    }

    /**
     * Ancestors as id/title pairs, ordered root → direct parent; the model
     * itself is excluded. Requires the model to define a `title` column.
     * Depth-guarded like ancestorIds() to survive pre-existing cycles.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function ancestorChain(): array
    {
        $chain = [];
        $current = $this;
        $depth = 0;

        while ($current->parent_id !== null && $depth < 20) {
            $current = $current->parent()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->firstOrFail();
            $chain[] = ['id' => $current->id, 'title' => $current->title];
            $depth++;
        }

        return array_reverse($chain);
    }
}
