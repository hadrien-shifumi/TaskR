<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    // The memberships table carries an auto-increment integer id, unlike
    // the ULID pivots: create() must hydrate the key for immediate use.
    public $incrementing = true;

    protected $table = 'memberships';

    protected $fillable = ['user_id', 'workspace_id', 'role', 'is_scoped'];

    protected $casts = [
        'role' => WorkspaceRole::class,
        'is_scoped' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The projects granted to a scoped member. Rows only exist when the
     * membership carries the is_scoped marker — an unscoped member has
     * full workspace access and no perimeter rows.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projectScope(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'membership_project', 'membership_id', 'project_id');
    }

    /**
     * Granted project ids, empty for an unscoped or empty perimeter.
     *
     * @return list<string>
     */
    public function projectScopeIds(): array
    {
        return MembershipProject::query()
            ->where('membership_id', $this->getKey())
            ->pluck('project_id')
            ->all();
    }

    /**
     * Replace the granted perimeter. Rows are recreated through the pivot
     * model so the ULID id is generated — attach() inserts raw pivot rows
     * and would leave it null.
     *
     * @param  array<int, string>  $projectIds
     */
    public function syncProjectScope(array $projectIds): void
    {
        MembershipProject::query()
            ->where('membership_id', $this->getKey())
            ->delete();

        foreach (array_values($projectIds) as $projectId) {
            MembershipProject::create([
                'membership_id' => $this->getKey(),
                'project_id' => $projectId,
            ]);
        }
    }
}
