<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProjectMember extends Pivot
{
    use BelongsToWorkspace;
    use HasUlids;

    protected $table = 'project_members';

    protected $fillable = ['workspace_id', 'project_id', 'user_id', 'role'];

    protected $casts = [
        'role' => ProjectRole::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
