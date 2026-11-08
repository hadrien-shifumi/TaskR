<?php

namespace App\Models;

use App\Enums\AuditEventType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit journal entry.
 *
 * A row records what happened (type), who did it (actor, null for system
 * actions), when (created_at) and on which subject (issue, project,
 * knowledge page or milestone), with the before/after values of the
 * changed fields in `changes` ({ field: { before, after } }).
 *
 * `comment_id` links a comment event to the comment it is about, without
 * going through `changes` (an id is metadata, not a diff). No FK on purpose:
 * a `comment.deleted` event must keep pointing at the removed comment.
 *
 * The journal is immutable: model events and query-level mass updates or
 * deletes are refused, so no code path can rewrite history.
 */
class AuditEvent extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'issue_id',
        'project_id',
        'knowledge_page_id',
        'milestone_id',
        'comment_id',
        'actor_id',
        'type',
        'changes',
    ];

    protected $casts = [
        'type' => AuditEventType::class,
        'changes' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit events are append-only.');
        });

        // Covers both delete() and forceDelete() (the latter routes through
        // delete() on a model without SoftDeletes).
        static::deleting(function (): void {
            throw new LogicException('Audit events are append-only.');
        });
    }

    /**
     * Query-level mass updates and deletes bypass model events: the builder
     * refuses them so the append-only guarantee holds for every write path.
     */
    public function newEloquentBuilder($query): AuditEventBuilder
    {
        return new AuditEventBuilder($query);
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<KnowledgePage, $this>
     */
    public function knowledgePage(): BelongsTo
    {
        return $this->belongsTo(KnowledgePage::class, 'knowledge_page_id');
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
