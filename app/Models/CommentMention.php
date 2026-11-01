<?php

namespace App\Models;

use App\Enums\MentionType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentMention extends BaseModel
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = ['workspace_id', 'comment_id', 'user_id', 'type', 'project_id', 'read_at'];

    protected $casts = [
        'type' => MentionType::class,
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Mentions of one account within its reachable perimeter. The
     * workspace filter comes from the global WorkspaceScope; a guest's
     * roster trims the rows whose issue sits outside their projects.
     */
    public function scopeForAccount(Builder $query, User $user): Builder
    {
        $query->where('user_id', $user->getKey());

        $scope = app(WorkspaceContext::class)->accessibleProjectIds($user);

        if ($scope !== null) {
            $query->whereHas('comment.issue', fn (Builder $issue) => $issue->whereIn('project_id', $scope));
        }

        return $query;
    }
}
