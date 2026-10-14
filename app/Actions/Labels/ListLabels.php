<?php

namespace App\Actions\Labels;

use App\Models\Workspace;
use Lorisleiva\Actions\Concerns\AsObject;

class ListLabels
{
    use AsObject;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Workspace $workspace): array
    {
        return $workspace->labels()
            ->orderBy('name')
            ->get()
            ->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])
            ->values()
            ->all();
    }
}
