<?php

namespace App\Models;

use App\Enums\DigestFrequency;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-account digest settings. Not workspace-scoped: a user has one row,
 * shared across every workspace they belong to.
 */
class NotificationPreference extends BaseModel
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'enabled',
        'frequency',
        'upcoming_threshold_days',
        'timezone',
        'last_digest_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'frequency' => DigestFrequency::class,
        'upcoming_threshold_days' => 'integer',
        'last_digest_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
