<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'description',
        'status_id',
        'priority',
        'due_date',
    ];

    protected $casts = [
        'priority' => Priority::class,
        'due_date' => 'date',
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
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Walk up the ancestor chain; used for cycle detection.
     *
     * @return array<string, string>
     */
    public function ancestorIds(): array
    {
        $ids = [];
        $current = $this;

        while ($current->parent_id !== null) {
            if (in_array($current->parent_id, $ids, true)) {
                break;
            }

            $ids[] = $current->parent_id;
            $current = $current->parent()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->firstOrFail();
        }

        return $ids;
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
}
