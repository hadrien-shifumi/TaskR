<?php

namespace App\Actions\Statuses;

use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsObject;

class ListStatuses
{
    use AsObject;

    /**
     * Workspace statuses with their semantic category, ordered by position.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace): array
    {
        return $workspace->statuses()
            ->get()
            ->map(fn ($status) => [
                'id' => $status->id,
                'name' => $status->name,
                'category' => $status->category->value,
                'position' => $status->position,
            ])
            ->values()
            ->all();
    }
}
