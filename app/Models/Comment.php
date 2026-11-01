<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends BaseModel
{
    use HasFactory;

    protected $fillable = ['issue_id', 'author_id', 'body'];

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mention rows synced from the body by SyncCommentMentions.
     *
     * @return HasMany<CommentMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class);
    }
}
