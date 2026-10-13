<?php

namespace App\Models\Scopes;

use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            // Without a current workspace, no workspace-scoped data is reachable.
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
