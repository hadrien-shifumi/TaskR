<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = [
        'workspace_id',
        'issue_id',
        'author_id',
        'worked_at',
        'hours',
        'note',
    ];

    protected $casts = [
        'worked_at' => 'date',
        'hours' => 'decimal:2',
    ];

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
        return $this->belongsTo(User::class, 'author_id');
    }
}
