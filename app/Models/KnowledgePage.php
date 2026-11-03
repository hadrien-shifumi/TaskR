<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasAncestorChain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Knowledge page (wiki node) belonging to a project.
 *
 * The SoftDeletes macros below are registered at runtime by
 * Illuminate\Database\Eloquent\SoftDeletingScope::extend(); they are declared
 * here so static analysis resolves them on the model and on Builder<KnowledgePage>.
 *
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withoutTrashed()
 * @method static Builder<static> onlyTrashed()
 */
class KnowledgePage extends BaseModel
{
    use BelongsToWorkspace, HasAncestorChain, HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'body',
        'last_editor_id',
        'deleted_by_id',
        'deleted_reason',
    ];

    protected $casts = [
        'embedded_at' => 'datetime',
        // pgvector's text format ([1,0.5,...]) is valid JSON: one cast covers
        // both the pgsql vector column and the sqlite JSON text column.
        'embedding' => 'array',
        'deleted_at' => 'datetime',
    ];

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
    public function parent(): BelongsTo
    {
        return $this->belongsTo(KnowledgePage::class, 'parent_id');
    }

    /**
     * @return HasMany<KnowledgePage, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(KnowledgePage::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_editor_id');
    }

    /**
     * All versions of this page (history).
     *
     * @return HasMany<KnowledgePageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgePageVersion::class, 'page_id');
    }

    /**
     * User who moved this page to trash.
     *
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id');
    }

    /**
     * Issues linked to this knowledge page.
     *
     * @return BelongsToMany<Issue, $this>
     */
    public function linkedIssues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_knowledge_pages', 'knowledge_page_id', 'issue_id');
    }

    /**
     * Scope: only trashed pages.
     *
     * @param  Builder<KnowledgePage>  $query
     * @return Builder<KnowledgePage>
     */
    public function scopeOnlyTrashed(Builder $query): Builder
    {
        return $query->onlyTrashed();
    }

    /**
     * Scope: trashed pages deleted for a specific reason.
     *
     * @param  Builder<KnowledgePage>  $query
     * @return Builder<KnowledgePage>
     */
    public function scopeTrashedWithReason(Builder $query, string $reason): Builder
    {
        return $query->onlyTrashed()->where('deleted_reason', $reason);
    }
}
