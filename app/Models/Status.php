<?php

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Status extends BaseModel
{
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'name', 'category', 'position'];

    protected $casts = [
        'category' => StatusCategory::class,
    ];

    /**
     * Interim guard until the issues table lands (change task 5.1);
     * will become a real relation count then.
     */
    public function issuesCount(): int
    {
        if (! Schema::hasTable('issues')) {
            return 0;
        }

        return (int) DB::table('issues')->where('status_id', $this->id)->count();
    }
}
