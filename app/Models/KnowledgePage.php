<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasAncestorChain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgePage extends BaseModel
{
    use BelongsToWorkspace, HasAncestorChain, HasFactory;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'parent_id',
        'title',
        'body',
        'last_editor_id',
    ];

    protected $casts = [
        'embedded_at' => 'datetime',
        // pgvector's text format ([1,0.5,...]) is valid JSON: one cast covers
        // both the pgsql vector column and the sqlite JSON text column.
        'embedding' => 'array',
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
}
