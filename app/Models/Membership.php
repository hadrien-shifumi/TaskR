<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    protected $table = 'memberships';

    protected $fillable = ['user_id', 'workspace_id', 'role'];

    protected $casts = [
        'role' => WorkspaceRole::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
