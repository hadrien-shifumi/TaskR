<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedView extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'summary', 'filters', 'sort', 'dir', 'created_by'];

    protected $casts = [
        'filters' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
