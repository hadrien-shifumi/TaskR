<?php

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Status extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'category', 'position'];

    protected $casts = [
        'category' => StatusCategory::class,
    ];
}
