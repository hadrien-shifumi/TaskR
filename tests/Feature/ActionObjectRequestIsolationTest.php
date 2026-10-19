<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ListIssues;
use App\Actions\Issues\MoveIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Object runs must never depend on ambient HTTP state: the acting user
 * is always an explicit handle() argument, never resolved from the
 * current request. A guest left in the request by an unrelated actingAs
 * therefore has no influence on an object run without an explicit user.
 */
class ActionObjectRequestIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    private function addGuest(Workspace $workspace): User
    {
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        return $guest;
    }

    public function test_object_run_ignores_ambient_request_user_on_create(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $projectIn = CreateProject::run($workspace, $owner, ['name' => 'Périmètre']);
        $projectOut = CreateProject::run($workspace, $owner, ['name' => 'Hors périmètre']);
        $guest = $this->addGuest($workspace);
        ProjectMember::create(['project_id' => $projectIn->id, 'user_id' => $guest->id, 'role' => 'member']);

        // Ambient request carries the guest (exactly what the auth
        // middleware leaves behind); the object run passes no user: a
        // trusted internal create must ignore the guest scope.
        request()->setUserResolver(fn () => $guest);

        $issue = CreateIssue::run($workspace, ['project_id' => $projectOut->id, 'title' => 'Ticket interne']);

        $this->assertSame($projectOut->id, $issue->project_id);
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'project_id' => $projectOut->id]);
    }

    public function test_object_run_ignores_ambient_request_user_on_guarded_mutation(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $projectIn = CreateProject::run($workspace, $owner, ['name' => 'Périmètre']);
        $projectOut = CreateProject::run($workspace, $owner, ['name' => 'Hors périmètre']);
        $guest = $this->addGuest($workspace);
        ProjectMember::create(['project_id' => $projectIn->id, 'user_id' => $guest->id, 'role' => 'member']);

        $issue = CreateIssue::run($workspace, ['project_id' => $projectOut->id, 'title' => 'Ticket interne']);
        /** @var Status $target */
        $target = $workspace->statuses()->skip(1)->firstOrFail();

        request()->setUserResolver(fn () => $guest);

        $moved = MoveIssue::run($issue, $target->id);

        $this->assertSame($target->id, $moved->status_id);
    }

    public function test_object_run_ignores_ambient_request_user_on_list_scope(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $projectIn = CreateProject::run($workspace, $owner, ['name' => 'Périmètre']);
        $projectOut = CreateProject::run($workspace, $owner, ['name' => 'Hors périmètre']);
        $guest = $this->addGuest($workspace);
        ProjectMember::create(['project_id' => $projectIn->id, 'user_id' => $guest->id, 'role' => 'member']);

        CreateIssue::run($workspace, ['project_id' => $projectIn->id, 'title' => 'Dans le périmètre']);
        CreateIssue::run($workspace, ['project_id' => $projectOut->id, 'title' => 'Hors du périmètre']);

        // Ambient guest would restrict the list to their perimeter; the
        // object run must see every issue of the workspace.
        request()->setUserResolver(fn () => $guest);

        $result = ListIssues::run($workspace);

        $this->assertCount(2, $result['issues']);
        $this->assertSame(2, $result['total']);
    }
}
