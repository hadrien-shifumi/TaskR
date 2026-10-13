<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
