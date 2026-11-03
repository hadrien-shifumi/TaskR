<?php

namespace App\Presenters\Audit;

use App\Models\AuditEvent;

class AuditEventPresenter
{
    /**
     * Timeline row of the issue fiche (descending order is chosen by the
     * query, never here). A missing actor stays null: the frontend renders
     * system events. `commentId` is a metadata of comment events, kept out
     * of `changes` — which only carries field diffs.
     *
     * @return array{id: string, type: string, actor: array{id: int, name: string}|null, createdAt: string|null, commentId: string|null, changes: array<string, mixed>}
     */
    public static function detail(AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'actor' => $event->actor !== null
                ? ['id' => $event->actor->id, 'name' => $event->actor->name]
                : null,
            'createdAt' => $event->created_at?->toISOString(),
            'commentId' => $event->comment_id,
            'changes' => $event->changes ?? [],
        ];
    }
}
