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
