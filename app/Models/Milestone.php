<?php

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasAncestorChain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends BaseModel
{
    use BelongsToWorkspace, HasAncestorChain, HasFactory;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'description',
        'start_date',
        'target_date',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'target_date' => 'date',
        'completed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Milestone::class, 'parent_id');
    }

    /**
     * @return HasMany<Milestone, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Milestone::class, 'parent_id');
    }

    /**
     * Issues attached to this milestone (at most one milestone per issue).
     *
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * A milestone is late while its target date is passed without a
     * manual completion — the same semantics as Issue::isOverdue().
     */
    public function isOverdue(): bool
    {
        return $this->target_date !== null
            && $this->target_date->isPast()
            && $this->completed_at === null;
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('target_date')
            ->whereDate('target_date', '<', now())
            ->whereNull('completed_at');
    }

    /**
     * Eager issue counts for the list surfaces: the total of unarchived
     * issues and the done ones (status category), each as one SQL
     * subquery — never a per-row query. The presenter reads the
     * issues_count / done_issues_count columns.
     *
     * @return array<string, callable(Builder): Builder>
     */
    public static function issueCounts(): array
    {
        return [
            'issues' => fn (Builder $inner) => $inner->whereNull('issues.archived_at'),
            'issues as done_issues_count' => fn (Builder $inner) => $inner
                ->join('statuses', 'statuses.id', '=', 'issues.status_id')
                ->where('statuses.category', StatusCategory::Done->value)
                ->whereNull('issues.archived_at'),
        ];
    }

    public function scopeWithIssueCounts(Builder $query): Builder
    {
        return $query->withCount(self::issueCounts());
    }
}
