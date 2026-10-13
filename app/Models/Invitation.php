<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invitation extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'email', 'token', 'expires_at', 'accepted_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /**
     * Invitations are accepted cross-workspace by design (the invitee may not
     * be a member of — or have any workspace context for — the inviting one),
     * so route binding bypasses the workspace global scope.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutWorkspaceScope()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }
}
