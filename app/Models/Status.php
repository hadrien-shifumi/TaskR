<?php

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'category', 'position'];

    protected $casts = [
        'category' => StatusCategory::class,
    ];

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function issuesCount(): int
    {
        return (int) $this->issues()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->count();
    }
}
