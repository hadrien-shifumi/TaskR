<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\SetIssueMilestone;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueMilestoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project}
     */
    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        return [$owner, $workspace, $project];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: Issue, 4: Milestone}
     */
    private function setupIssueAndMilestone(): array
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Ticket']);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        return [$owner, $workspace, $project, $issue, $milestone];
    }

    public function test_issue_can_be_attached_to_a_milestone(): void
    {
        [$owner, , , $issue, $milestone] = $this->setupIssueAndMilestone();

        $this->actingAs($owner)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => $milestone->id,
        ]);

        $event = AuditEvent::query()
            ->where('issue_id', $issue->id)
            ->where('type', 'issue.milestone_set')
            ->firstOrFail();

        $this->assertNull($event->changes['milestone_id']['before']);
        $this->assertSame($milestone->id, $event->changes['milestone_id']['after']);
    }

    public function test_issue_milestone_can_be_moved(): void
    {
        [$owner, $workspace, $project, $issue, $milestone] = $this->setupIssueAndMilestone();
        SetIssueMilestone::run($issue, $milestone->id, $owner);

        $other = CreateMilestone::run($project, ['title' => 'V2'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $other->id])
            ->assertRedirect();

        // One issue, at most one milestone: the move replaces, never stacks.
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => $other->id,
        ]);
        $this->assertSame(1, $issue->milestone()->count());
    }

    public function test_issue_milestone_can_be_cleared(): void
    {
        [$owner, , , $issue, $milestone] = $this->setupIssueAndMilestone();
        SetIssueMilestone::run($issue, $milestone->id, $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $issue), ['milestone_id' => null])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);

        $event = AuditEvent::query()
            ->where('issue_id', $issue->id)
            ->where('type', 'issue.milestone_removed')
            ->firstOrFail();

        $this->assertSame($milestone->id, $event->changes['milestone_id']['before']);
        $this->assertNull($event->changes['milestone_id']['after']);
    }

    public function test_milestone_from_another_project_is_refused(): void
    {
        [$owner, $workspace, $project, $issue] = $this->setupIssueAndMilestone();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Autre projet'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $foreign->id])
            ->assertSessionHasErrors('milestone_id');

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_milestone_from_another_workspace_is_refused(): void
    {
        [$owner, , , $issue] = $this->setupIssueAndMilestone();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Ailleurs']);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Etrangère'], $otherOwner);
        app(WorkspaceContext::class)->set(
            Workspace::query()->where('name', 'WS')->firstOrFail()
        );

        $this->actingAs($owner)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $foreign->id])
            ->assertSessionHasErrors('milestone_id');

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_project_member_can_attach_a_milestone(): void
    {
        [$owner, $workspace, $project, $issue, $milestone] = $this->setupIssueAndMilestone();

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => $milestone->id,
        ]);
    }

    public function test_workspace_member_outside_roster_cannot_attach(): void
    {
        [$owner, $workspace, , $issue, $milestone] = $this->setupIssueAndMilestone();

        $outsider = User::factory()->create();
        Membership::create([
            'user_id' => $outsider->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($outsider)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertStatus(403);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_guest_cannot_attach(): void
    {
        [$owner, $workspace, $project, $issue, $milestone] = $this->setupIssueAndMilestone();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertStatus(403);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_issue_detail_includes_the_milestone(): void
    {
        [$owner, , , $issue, $milestone] = $this->setupIssueAndMilestone();
        SetIssueMilestone::run($issue, $milestone->id, $owner);

        $this->actingAs($owner)
            ->get(route('issues.show', $issue))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.milestone.id', $milestone->id)
                ->where('issue.milestone.title', 'V1')
                ->where('issue.milestone.overdue', false));
    }

    public function test_deleting_a_milestone_detaches_its_issues(): void
    {
        [$owner, , , $issue, $milestone] = $this->setupIssueAndMilestone();
        SetIssueMilestone::run($issue, $milestone->id, $owner);

        DeleteMilestone::run($milestone, $owner);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    // ── Création d'un ticket déjà rattaché ───────────────────────────

    public function test_issue_can_be_created_with_a_milestone_of_its_project(): void
    {
        [$owner, $workspace, $project, , $milestone] = $this->setupIssueAndMilestone();

        $this->actingAs($owner)
            ->post(route('issues.store'), [
                'project_id' => $project->id,
                'title' => 'Nouveau ticket',
                'milestone_id' => $milestone->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'title' => 'Nouveau ticket',
            'milestone_id' => $milestone->id,
        ]);

        // The birth attachment rides the creation event's diff.
        $event = AuditEvent::query()
            ->where('issue_id', Issue::query()->where('title', 'Nouveau ticket')->firstOrFail()->id)
            ->where('type', 'issue.created')
            ->firstOrFail();

        $this->assertNull($event->changes['milestone_id']['before']);
        $this->assertSame($milestone->id, $event->changes['milestone_id']['after']);
    }

    public function test_issue_creation_refuses_a_milestone_of_another_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Autre projet'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.store'), [
                'project_id' => $project->id,
                'title' => 'Nouveau ticket',
                'milestone_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('milestone_id');

        $this->assertDatabaseMissing('issues', [
            'project_id' => $project->id,
            'title' => 'Nouveau ticket',
        ]);
    }

    public function test_issue_creation_refuses_a_cross_workspace_milestone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Ailleurs']);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Etrangère'], $otherOwner);
        app(WorkspaceContext::class)->set(
            Workspace::query()->where('name', 'WS')->firstOrFail()
        );

        $this->actingAs($owner)
            ->post(route('issues.store'), [
                'project_id' => $project->id,
                'title' => 'Nouveau ticket',
                'milestone_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('milestone_id');

        $this->assertDatabaseMissing('issues', [
            'project_id' => $project->id,
            'title' => 'Nouveau ticket',
        ]);
    }

    public function test_issue_created_without_a_milestone_stays_unattached(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('issues.store'), [
                'project_id' => $project->id,
                'title' => 'Nouveau ticket',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'title' => 'Nouveau ticket',
            'milestone_id' => null,
        ]);
    }

    // ── Props des pages ──────────────────────────────────────────────

    public function test_issue_page_carries_milestone_options_and_contribute_flag(): void
    {
        [$owner, $workspace, $project, $issue, $milestone] = $this->setupIssueAndMilestone();

        // Owner: contributor of the project, options listed by title.
        $this->actingAs($owner)
            ->get(route('issues.show', $issue))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canContribute', true)
                ->has('milestoneOptions', 1)
                ->where('milestoneOptions.0.id', $milestone->id)
                ->where('milestoneOptions.0.title', 'V1'));

        // Guest on the roster: reads the fiche, never contributes.
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $this->actingAs($guest)
            ->get(route('issues.show', $issue))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canContribute', false)
                ->has('milestoneOptions', 1));
    }

    public function test_board_page_carries_milestone_options(): void
    {
        [$owner, , $project, , $milestone] = $this->setupIssueAndMilestone();

        $this->actingAs($owner)
            ->get(route('projects.board', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('milestones', 1)
                ->where('milestones.0.id', $milestone->id)
                ->where('milestones.0.title', 'V1'));
    }
}
