<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for all business entities.
 *
 * Business entities use ULID primary keys (opaque, chronologically sortable)
 * and every model in a workspace scope MUST define its `workspace_id` column
 * and use the BelongsToWorkspace trait from App\Models\Concerns.
 */
abstract class BaseModel extends Model
{
    use HasUlids;
}
