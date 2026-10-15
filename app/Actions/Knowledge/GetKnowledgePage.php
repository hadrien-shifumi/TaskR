<?php

namespace App\Actions\Knowledge;

use App\Models\KnowledgePage;
use Lorisleiva\Actions\Concerns\AsObject;

class GetKnowledgePage
{
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(KnowledgePage $page): array
    {
        $page->loadMissing('lastEditor');

        return [
            'id' => $page->id,
            'title' => $page->title,
            'body' => $page->body,
            'parentId' => $page->parent_id,
            'projectId' => $page->project_id,
            'lastEditor' => $page->lastEditor?->name,
            'updatedAt' => $page->updated_at?->toISOString(),
        ];
    }
}
