<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable point-in-time snapshot of a knowledge page.
 *
 * Versions are not workspace-scoped directly: the workspace is inherited
 * through the page() relation (which is workspace-scoped). They are never
 * soft-deleted — history is kept forever — and never updated.
 */
class KnowledgePageVersion extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'page_id',
        'title',
        'body',
        'editor_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * The knowledge page this version belongs to.
     *
     * @return BelongsTo<KnowledgePage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(KnowledgePage::class, 'page_id');
    }

    /**
     * The user who created this version.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
