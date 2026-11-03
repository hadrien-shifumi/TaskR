<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasAncestorChain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends BaseModel
{
    use BelongsToWorkspace, HasAncestorChain, HasFactory;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'description',
        'status_id',
        'priority',
        'due_date',
        'estimated_hours',
        'created_by',
        'archived_at',
    ];

    protected $casts = [
        'priority' => Priority::class,
        'due_date' => 'date',
        'estimated_hours' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'parent_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Issue::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'issue_labels');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_assignees');
    }

    /**
     * Issues that must be completed before this one.
     *
     * @return BelongsToMany<Issue, $this>
     */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_dependencies', 'issue_id', 'blocked_by_id');
    }

    /**
     * Issues that cannot start until this one is completed.
     *
     * @return BelongsToMany<Issue, $this>
     */
    public function blocks(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_dependencies', 'blocked_by_id', 'issue_id');
    }

    /**
     * Knowledge pages linked to this issue.
     *
     * @return BelongsToMany<KnowledgePage, $this>
     */
    public function knowledgePages(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgePage::class, 'issue_knowledge_pages');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Total hours logged across all time entries for this issue.
     */
    public function getLoggedHoursAttribute(): float
    {
        if ($this->relationLoaded('timeEntries')) {
            return (float) $this->timeEntries->sum('hours');
        }

        return (float) $this->timeEntries()->sum('hours');
    }

    /**
     * Remaining hours: estimate minus logged. Null when no estimate is set.
     * May be negative (overconsumption).
     */
    public function getRemainingHoursAttribute(): ?float
    {
        if ($this->estimated_hours === null) {
            return null;
        }

        return (float) $this->estimated_hours - $this->logged_hours;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status?->category !== StatusCategory::Done
            && $this->status?->category !== StatusCategory::Cancelled;
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now());
    }

    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
