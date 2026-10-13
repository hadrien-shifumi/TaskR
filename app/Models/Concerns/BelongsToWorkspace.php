<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as workspace-scoped.
 *
 * Adds a global scope restricting every query to the current workspace
 * and auto-fills workspace_id on creation from the WorkspaceContext.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model): void {
            if ($model->workspace_id === null) {
                $model->workspace_id = app(WorkspaceContext::class)->id();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function withoutWorkspaceScope(): mixed
    {
        return static::withoutGlobalScope(WorkspaceScope::class);
    }
}
