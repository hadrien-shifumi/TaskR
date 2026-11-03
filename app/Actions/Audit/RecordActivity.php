<?php

namespace App\Actions\Audit;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\KnowledgePage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Lorisleiva\Actions\Concerns\AsObject;

class RecordActivity
{
    use AsObject;

    /**
     * Append one event to the audit journal.
     *
     * Internal object action only (`::run()`): business actions call it when
     * they mutate a subject. The workspace is auto-filled from the
     * WorkspaceContext by BelongsToWorkspace; the subject FK is derived from
     * the model type.
     *
     * `changes` carries either a before/after pair per changed field
     * (`['status' => ['before' => 'Backlog', 'after' => 'In Progress']]`) or a
     * flat list for "added/removed" diffs (`['assignees_added' => [...]]`,
     * `['knowledge_pages_removed' => [...]]`). Free-text bodies are never
     * journalled on creation or update — they live with their object.
     *
     * @param  array<string, mixed>  $changes
     */
    public function handle(
        AuditEventType $type,
        Model $subject,
        ?User $actor = null,
        array $changes = [],
        ?string $commentId = null,
    ): AuditEvent {
        $subjectKey = $subject->getKey();

        // An unknown subject would produce an orphan event (no FK set):
        // refuse it instead of silently journaling nothing.
        if (! $subject instanceof Issue && ! $subject instanceof Project && ! $subject instanceof KnowledgePage) {
            throw new LogicException(sprintf(
                'Unsupported audit subject [%s]: expected an Issue, a Project or a KnowledgePage.',
                $subject::class,
            ));
        }

        return AuditEvent::create([
            'issue_id' => $subject instanceof Issue ? $subjectKey : null,
            // An issue event also carries its project: the project activity
            // view filters on this column alone.
            'project_id' => match (true) {
                $subject instanceof Issue => $subject->project_id,
                $subject instanceof Project => $subjectKey,
                default => null,
            },
            'knowledge_page_id' => $subject instanceof KnowledgePage ? $subjectKey : null,
            'comment_id' => $commentId,
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'changes' => $changes,
        ]);
    }
}
