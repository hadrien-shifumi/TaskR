<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The workspace's own embeddings provider (knowledge base semantic
 * search): URL, model, API key and the dimension observed by the
 * save-time probe. One row per workspace; when absent, the instance's
 * global configuration (config/knowledge.php) applies.
 *
 * The API key is encrypted at rest (`encrypted` cast) and is never
 * serialized to any Inertia or JSON payload — surfaces expose a boolean
 * `hasApiKey` instead.
 */
class WorkspaceEmbeddingSetting extends BaseModel
{
    use BelongsToWorkspace;

    protected $table = 'workspace_embedding_settings';

    protected $fillable = [
        'workspace_id',
        'base_url',
        'api_key',
        'model',
        'dimensions',
        'send_dimensions',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'dimensions' => 'integer',
        'send_dimensions' => 'boolean',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
