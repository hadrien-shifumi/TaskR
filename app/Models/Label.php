<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Label extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'color'];

    /**
     * @return BelongsToMany<Issue, $this>
     */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'issue_labels');
    }
}
