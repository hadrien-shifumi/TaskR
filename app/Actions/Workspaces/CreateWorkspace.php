<?php

namespace App\Actions\Workspaces;

use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateWorkspace
{
    use AsObject;

    /**
     * @param  array{name: string}  $data
     */
    public function handle(User $owner, array $data): Workspace
    {
        return DB::transaction(function () use ($owner, $data): Workspace {
            $workspace = Workspace::create(['name' => $data['name']]);

            $workspace->memberships()->create([
                'user_id' => $owner->getKey(),
                'role' => WorkspaceRole::Owner->value,
            ]);

            $this->createDefaultStatuses($workspace);

            return $workspace;
        });
    }

    private function createDefaultStatuses(Workspace $workspace): void
    {
        $defaults = [
            ['name' => 'Backlog', 'category' => StatusCategory::Backlog],
            ['name' => 'In Progress', 'category' => StatusCategory::InProgress],
            ['name' => 'Done', 'category' => StatusCategory::Done],
            ['name' => 'Cancelled', 'category' => StatusCategory::Cancelled],
        ];

        foreach ($defaults as $position => $status) {
            Status::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'name' => $status['name'],
                'category' => $status['category'],
                'position' => $position,
            ]);
        }
    }
}
