<?php

namespace App\Actions\Workspaces;

use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateWorkspace
{
    use AsController;
    use AsObject;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

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

    /**
     * Web entry point (workspace switcher menu): creates the workspace,
     * makes it the active one and lands on the dashboard.
     */
    public function asController(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $workspace = $this->handle($user, $request->all());

        session(['workspace_id' => $workspace->id]);

        return redirect()
            ->route('home')
            ->with('status', "Workspace \"{$workspace->name}\" created.");
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
