<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\LoadActiveDescendants;
use App\Actions\Issues\SetIssueParent;
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
use App\Presenters\Issues\IssuePresenter;
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

    public function test_workspace_member_outside_the_project_team_gets_404(): void
    {
        [$owner, $workspace, , $issue, $milestone] = $this->setupIssueAndMilestone();

        $outsider = User::factory()->create();
        Membership::create([
            'user_id' => $outsider->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        // Off the team, the issue behaves as non-existent — 404, never
        // a 403 that would confirm it exists.
        $this->actingAs($outsider)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertNotFound();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_guest_cannot_attach(): void
    {
        [$owner, $workspace, $project, $issue, $milestone] = $this->setupIssueAndMilestone();

        // The roster role alone decides: a project Guest never writes,
        // whatever their workspace role. The refusal answers like a
        // non-existent issue (404), never a 403.
        $projectGuest = User::factory()->create();
        Membership::create([
            'user_id' => $projectGuest->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $projectGuest->id,
            'role' => 'guest',
        ]);

        $this->actingAs($projectGuest)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertNotFound();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_workspace_guest_roster_member_can_attach(): void
    {
        [$owner, $workspace, $project, $issue, $milestone] = $this->setupIssueAndMilestone();

        // D7: the workspace role never caps project rights — a workspace
        // guest carrying a roster Member line attaches like anyone.
        $workspaceGuest = User::factory()->create();
        Membership::create([
            'user_id' => $workspaceGuest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $workspaceGuest->id,
            'role' => 'member',
        ]);

        $this->actingAs($workspaceGuest)
            ->post(route('issues.milestone', $issue), ['milestone_id' => $milestone->id])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => $milestone->id,
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

        // D7: the roster line carries the rights, the workspace role
        // never caps them — a workspace guest rostered Member is a
        // contributor like any other.
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
                ->where('canContribute', true)
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

    // ── Cascade de la descendance ────────────────────────────────────

    /**
     * A three-level tree: parent → child → grandchild, none attached.
     *
     * @return array{parent: Issue, child: Issue, grandchild: Issue}
     */
    private function seedThreeLevels(Workspace $workspace, Project $project): array
    {
        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Parent']);
        $child = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Child']);
        $grandchild = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Grandchild']);
        SetIssueParent::run($child, $parent->id);
        SetIssueParent::run($grandchild, $child->id);

        return ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild];
    }

    public function test_cascade_children_attaches_the_whole_active_subtree_over_three_levels(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        foreach ([$parent, $child, $grandchild] as $issue) {
            $this->assertSame($milestone->id, $issue->fresh()->milestone_id);
        }
    }

    public function test_without_cascade_only_the_parent_is_attached(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), ['milestone_id' => $milestone->id])
            ->assertRedirect();

        $this->assertSame($milestone->id, $parent->fresh()->milestone_id);
        $this->assertNull($child->fresh()->milestone_id);
        $this->assertNull($grandchild->fresh()->milestone_id);
    }

    public function test_cascade_skips_descendants_already_in_the_target_milestone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        // Arrange through the action: the child carries one real event,
        // the cascade must not add a second one.
        SetIssueMilestone::run($child, $milestone->id, $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        // The already-attached child moved nothing: exactly one event, its own.
        $this->assertSame(1, AuditEvent::query()
            ->where('issue_id', $child->id)
            ->where('type', 'issue.milestone_set')
            ->count());

        $this->assertSame($milestone->id, $parent->fresh()->milestone_id);
        $this->assertSame($milestone->id, $grandchild->fresh()->milestone_id);
    }

    public function test_cascade_switches_descendants_from_another_milestone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);
        $other = CreateMilestone::run($project, ['title' => 'V2'], $owner);

        $child->update(['milestone_id' => $other->id]);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        $this->assertSame($milestone->id, $child->fresh()->milestone_id);

        $event = AuditEvent::query()
            ->where('issue_id', $child->id)
            ->where('type', 'issue.milestone_set')
            ->firstOrFail();

        $this->assertSame($other->id, $event->changes['milestone_id']['before']);
        $this->assertSame($milestone->id, $event->changes['milestone_id']['after']);
    }

    public function test_cascade_never_touches_archived_descendants(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        // An archived child left the parent's scope: it never follows.
        $child->update(['archived_at' => now()]);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        $this->assertSame($milestone->id, $parent->fresh()->milestone_id);
        $this->assertNull($child->fresh()->milestone_id);
        $this->assertNotNull($child->fresh()->archived_at);
    }

    public function test_detaching_never_cascades_even_with_the_flag(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $parent->update(['milestone_id' => $milestone->id]);
        $child->update(['milestone_id' => $milestone->id]);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => null,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        // Detaching stays individual: the child keeps its milestone.
        $this->assertNull($parent->fresh()->milestone_id);
        $this->assertSame($milestone->id, $child->fresh()->milestone_id);
    }

    public function test_cascade_skips_descendants_of_another_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        // The hierarchy is workspace-wide: this child lives in another
        // project, outside the milestone's reach. It is skipped silently.
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);
        $child->update(['project_id' => $otherProject->id]);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        $this->assertSame($milestone->id, $parent->fresh()->milestone_id);
        $this->assertNull($child->fresh()->milestone_id);
    }

    public function test_guest_cascade_ignores_subtrees_outside_their_perimeter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $guest = User::factory()->create();
        Membership::create(['user_id' => $guest->id, 'workspace_id' => $workspace->id, 'role' => 'guest']);
        ProjectMember::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        // The child sits in a project outside the guest's perimeter.
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre']);
        $child->update(['project_id' => $otherProject->id]);

        $this->actingAs($guest)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        $this->assertSame($milestone->id, $parent->fresh()->milestone_id);
        $this->assertNull($child->fresh()->milestone_id);
    }

    public function test_every_cascaded_issue_journals_its_own_milestone_set_event(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        foreach ([$parent, $child, $grandchild] as $issue) {
            $event = AuditEvent::query()
                ->where('issue_id', $issue->id)
                ->where('type', 'issue.milestone_set')
                ->firstOrFail();

            $this->assertSame($owner->id, $event->actor_id);
            $this->assertNull($event->changes['milestone_id']['before']);
            $this->assertSame($milestone->id, $event->changes['milestone_id']['after']);
        }
    }

    public function test_cascade_children_must_be_a_boolean(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => 'maybe',
            ])
            ->assertSessionHasErrors('cascade_children');

        $this->assertNull($parent->fresh()->milestone_id);
    }

    public function test_cascade_applies_when_the_parent_already_carries_the_target_milestone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $parent->update(['milestone_id' => $milestone->id]);

        $this->actingAs($owner)
            ->post(route('issues.milestone', $parent), [
                'milestone_id' => $milestone->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        // The parent is unchanged (no new event), the child follows.
        $this->assertSame(0, AuditEvent::query()
            ->where('issue_id', $parent->id)
            ->where('type', 'issue.milestone_set')
            ->count());
        $this->assertSame($milestone->id, $child->fresh()->milestone_id);
    }

    public function test_the_descendant_summary_counts_active_descendants_per_milestone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($workspace, $project);
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $child->update(['milestone_id' => $milestone->id]);

        // An archived descendant never counts.
        $archived = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Archived']);
        SetIssueParent::run($archived, $parent->id);
        $archived->update(['archived_at' => now()]);

        $descendants = app(LoadActiveDescendants::class)->run(collect([$parent]));
        $detail = IssuePresenter::detail($parent, null, [], $descendants);

        $this->assertSame(['total' => 2, 'byStatus' => [
            $grandchild->status_id => 2,
        ], 'byMilestone' => [
            $milestone->id => 1,
        ]], $detail['descendants']);
    }
}
