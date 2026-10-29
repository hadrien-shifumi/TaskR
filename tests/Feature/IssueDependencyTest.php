<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IssueDependencyTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        return [$owner, $workspace, $project];
    }

    /**
     * Guest scoped to one project, with a second project out of reach.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: Issue, 4: User}
     */
    private function setupScopedGuest(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        $inScopeIssue = CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $inScope->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest];
    }

    public function test_blocked_by_and_blocks_are_bidirectional(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);

        $a->blockedBy()->sync([$b->id]);

        $this->assertTrue($a->blockedBy->contains($b));
        $this->assertTrue($b->blocks->contains($a));
    }

    public function test_sync_is_idempotent_no_duplicate_dependency(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);

        $a->blockedBy()->sync([$b->id]);
        $a->blockedBy()->sync([$b->id]);

        $this->assertSame(1, DB::table('issue_dependencies')->count());
    }

    public function test_deleting_an_issue_cascades_both_directions(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        $c = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C']);

        // A is blocked by B, and C is blocked by A.
        $a->blockedBy()->sync([$b->id]);
        $c->blockedBy()->sync([$a->id]);

        $a->delete();

        $this->assertSame(0, DB::table('issue_dependencies')->count());
        $this->assertSame([], $b->fresh()->blocks()->pluck('issues.id')->all());
        $this->assertSame([], $c->fresh()->blockedBy()->pluck('issues.id')->all());
    }

    // ── Sync via HTTP ────────────────────────────────────────────────

    public function test_sync_blocked_by_cross_project_same_workspace(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $otherProject->id, 'title' => 'B']);

        $this->actingAs($owner)
            ->post(route('issues.dependencies', $a), ['blocked_by' => [$b->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('issue_dependencies', [
            'issue_id' => $a->id,
            'blocked_by_id' => $b->id,
        ]);
    }

    public function test_self_block_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);

        $this->actingAs($owner)
            ->post(route('issues.dependencies', $a), ['blocked_by' => [$a->id]])
            ->assertStatus(422);

        $this->assertDatabaseCount('issue_dependencies', 0);
    }

    public function test_two_node_cycle_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);

        $a->blockedBy()->sync([$b->id]);

        // B -> A would close the cycle A -> B.
        $this->actingAs($owner)
            ->post(route('issues.dependencies', $b), ['blocked_by' => [$a->id]])
            ->assertStatus(422);

        $this->assertDatabaseMissing('issue_dependencies', [
            'issue_id' => $b->id,
            'blocked_by_id' => $a->id,
        ]);
    }

    public function test_three_node_cycle_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        $c = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C']);

        $a->blockedBy()->sync([$b->id]);
        $b->blockedBy()->sync([$c->id]);

        // C -> A would close the chain A -> B -> C -> A: the walk must
        // traverse the whole graph, not just the direct blockers.
        $this->actingAs($owner)
            ->post(route('issues.dependencies', $c), ['blocked_by' => [$a->id]])
            ->assertStatus(422);

        $this->assertDatabaseMissing('issue_dependencies', [
            'issue_id' => $c->id,
            'blocked_by_id' => $a->id,
        ]);
    }

    public function test_blocker_from_another_workspace_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignIssue = CreateIssue::run($otherWorkspace, [
            'project_id' => CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Ailleurs'])->id,
            'title' => 'Etranger',
        ]);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post(route('issues.dependencies', $a), ['blocked_by' => [$foreignIssue->id]])
            ->assertSessionHasErrors('blocked_by.0');

        $this->assertDatabaseCount('issue_dependencies', 0);
    }

    public function test_guest_cannot_link_out_of_perimeter_blocker(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.dependencies', $inScopeIssue->id), ['blocked_by' => [$outOfScopeIssue->id]])
            ->assertSessionHasErrors('blocked_by');

        $this->assertDatabaseCount('issue_dependencies', 0);
    }

    public function test_guest_issue_out_of_perimeter_is_not_found(): void
    {
        [$owner, $workspace, $inScopeIssue, $outOfScopeIssue, $guest] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->post(route('issues.dependencies', $outOfScopeIssue->id), ['blocked_by' => []])
            ->assertNotFound();

        $this->assertDatabaseCount('issue_dependencies', 0);
    }

    public function test_sync_replaces_the_full_list(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        $c = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C']);

        $a->blockedBy()->sync([$b->id]);

        $this->actingAs($owner)
            ->post(route('issues.dependencies', $a), ['blocked_by' => [$c->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('issue_dependencies', [
            'issue_id' => $a->id,
            'blocked_by_id' => $b->id,
        ]);
        $this->assertDatabaseHas('issue_dependencies', [
            'issue_id' => $a->id,
            'blocked_by_id' => $c->id,
        ]);
    }

    public function test_removal_from_the_other_side(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        $c = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'C']);

        // B is blocked by A and C; A is one of B's blockers.
        $b->blockedBy()->sync([$a->id, $c->id]);

        // Re-sync B's blockers without A: only that edge goes.
        $this->actingAs($owner)
            ->post(route('issues.dependencies', $b), ['blocked_by' => [$c->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('issue_dependencies', [
            'issue_id' => $b->id,
            'blocked_by_id' => $a->id,
        ]);
        $this->assertDatabaseHas('issue_dependencies', [
            'issue_id' => $b->id,
            'blocked_by_id' => $c->id,
        ]);
    }
}
