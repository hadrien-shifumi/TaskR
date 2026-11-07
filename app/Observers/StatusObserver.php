<?php

namespace App\Observers;

use App\Models\Status;
use App\Models\Workspace;

/**
 * Keeps the per-workspace statuses cache fresh: any status write drops
 * the cached list, the next read rebuilds it. The bulk reorder does not
 * fire model events and flushes the cache itself (ReorderStatuses).
 */
class StatusObserver
{
    public function created(Status $status): void
    {
        $this->flush($status);
    }

    public function updated(Status $status): void
    {
        $this->flush($status);
    }

    public function deleted(Status $status): void
    {
        $this->flush($status);
    }

    private function flush(Status $status): void
    {
        if ($status->workspace_id !== null) {
            Workspace::flushStatusesCache($status->workspace_id);
        }
    }
}
