<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneTest extends TestCase
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
     * Workspace member sitting on the project roster (role member).
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: User}
     */
    private function setupProjectMember(): array
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

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

        return [$owner, $workspace, $project, $member];
    }

    // ── Création ────────────────────────────────────────────────────

    public function test_minimal_create_persists_a_milestone(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('milestones.store', $project), ['title' => 'Version 1'])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Version 1',
            'parent_id' => null,
            'description' => null,
            'start_date' => null,
            'target_date' => null,
            'completed_at' => null,
            'created_by' => $owner->id,
        ]);
    }

    public function test_full_create_persists_every_field(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('milestones.store', $project), [
                'title' => 'Lancement',
                'description' => 'Mise en production',
                'start_date' => '2026-01-10',
                'target_date' => '2026-02-20',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'project_id' => $project->id,
            'title' => 'Lancement',
            'description' => 'Mise en production',
            // SQLite stores date columns with a midnight time part.
            'start_date' => '2026-01-10 00:00:00',
            'target_date' => '2026-02-20 00:00:00',
        ]);
    }

    public function test_create_is_journalled(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('milestones.store', $project), [
                'title' => 'Version 1',
                'start_date' => '2026-01-10',
                'target_date' => '2026-02-20',
            ])
            ->assertRedirect();

        $milestone = Milestone::query()->firstOrFail();

        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'issue_id' => null,
            'actor_id' => $owner->id,
            'type' => 'milestone.created',
        ]);
    }

    public function test_create_requires_a_title(): void
    {
        [$owner, , $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('milestones.store', $project), [])
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('milestones', 0);
    }

    public function test_project_member_can_create_a_milestone(): void
    {
        [, , $project, $member] = $this->setupProjectMember();

        $this->actingAs($member)
            ->post(route('milestones.store', $project), ['title' => 'Par un membre'])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'project_id' => $project->id,
            'title' => 'Par un membre',
            'created_by' => $member->id,
        ]);
    }

    public function test_workspace_member_outside_the_project_roster_cannot_create(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $outsider = User::factory()->create();
        Membership::create([
            'user_id' => $outsider->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $this->actingAs($outsider)
            ->post(route('milestones.store', $project), ['title' => 'Interdit'])
            ->assertStatus(403);

        $this->assertDatabaseCount('milestones', 0);
    }

    public function test_guest_cannot_create_even_within_their_roster_project(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

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
            ->post(route('milestones.store', $project), ['title' => 'Interdit'])
            ->assertStatus(403);

        $this->assertDatabaseCount('milestones', 0);
    }

    public function test_workspace_owner_can_create_without_being_in_the_roster(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        // The project exists without any roster row for the owner.
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->post(route('milestones.store', $project), ['title' => 'Par le propriétaire'])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'project_id' => $project->id,
            'title' => 'Par le propriétaire',
        ]);
    }

    public function test_target_date_before_start_date_is_refused(): void
    {
        [$owner, , $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('milestones.store', $project), [
                'title' => 'Incohérent',
                'start_date' => '2026-03-01',
                'target_date' => '2026-02-01',
            ])
            ->assertSessionHasErrors('target_date');

        $this->assertDatabaseCount('milestones', 0);
    }

    // ── Édition ─────────────────────────────────────────────────────

    public function test_update_changes_fields_and_journals_the_diff(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $this->actingAs($owner)
            ->put(route('milestones.update', $milestone), [
                'title' => 'Version 1.1',
                'description' => 'Recette',
                'start_date' => '2026-01-10',
                'target_date' => '2026-02-20',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'title' => 'Version 1.1',
            'description' => 'Recette',
            // SQLite stores date columns with a midnight time part.
            'start_date' => '2026-01-10 00:00:00',
            'target_date' => '2026-02-20 00:00:00',
        ]);

        $event = AuditEvent::query()
            ->where('milestone_id', $milestone->id)
            ->where('type', 'milestone.updated')
            ->firstOrFail();

        $this->assertSame('Version 1', $event->changes['title']['before']);
        $this->assertSame('Version 1.1', $event->changes['title']['after']);
        $this->assertSame('2026-02-20', $event->changes['target_date']['after']);
    }

    public function test_update_noop_journals_nothing(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $eventsBefore = AuditEvent::query()->where('milestone_id', $milestone->id)->count();

        $this->actingAs($owner)
            ->put(route('milestones.update', $milestone), [
                'title' => 'Version 1',
            ])
            ->assertRedirect();

        $this->assertSame(
            $eventsBefore,
            AuditEvent::query()->where('milestone_id', $milestone->id)->count(),
        );
    }

    public function test_update_cannot_push_start_date_past_the_completion_date(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);
        $milestone->forceFill(['completed_at' => now()])->save();

        $this->actingAs($owner)
            ->put(route('milestones.update', $milestone), [
                'title' => 'Version 1',
                'start_date' => now()->addYear()->toDateString(),
            ])
            ->assertSessionHasErrors('start_date');
    }

    // ── Isolation ───────────────────────────────────────────────────

    public function test_milestone_from_another_workspace_is_not_found(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->actingAs($otherOwner)
            ->put(route('milestones.update', $milestone), ['title' => 'Piratage'])
            ->assertNotFound();

        $this->actingAs($otherOwner)
            ->delete(route('milestones.destroy', $milestone))
            ->assertNotFound();

        $this->assertDatabaseHas('milestones', ['id' => $milestone->id]);
    }

    // ── Suppression ─────────────────────────────────────────────────

    public function test_delete_by_project_manager_succeeds(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $manager = User::factory()->create();
        Membership::create([
            'user_id' => $manager->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => 'manager',
        ]);

        $this->actingAs($manager)
            ->delete(route('milestones.destroy', $milestone))
            ->assertRedirect();

        $this->assertDatabaseMissing('milestones', ['id' => $milestone->id]);
    }

    public function test_delete_by_simple_member_is_refused(): void
    {
        [, , $project, $member] = $this->setupProjectMember();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $member);

        $this->actingAs($member)
            ->delete(route('milestones.destroy', $milestone))
            ->assertStatus(403);

        $this->assertDatabaseHas('milestones', ['id' => $milestone->id]);
    }

    public function test_delete_by_guest_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'Version 1'], $owner);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'manager',
        ]);

        $this->actingAs($guest)
            ->delete(route('milestones.destroy', $milestone))
            ->assertStatus(403);

        $this->assertDatabaseHas('milestones', ['id' => $milestone->id]);
    }

    public function test_delete_detaches_issues_and_reparents_children(): void
    {
        [$owner, , $project] = $this->setupWorkspace();

        $root = CreateMilestone::run($project, ['title' => 'V1'], $owner);
        $child = CreateMilestone::run($project, ['title' => 'V1.1'], $owner);
        $child->update(['parent_id' => $root->id]);
        $grandChild = CreateMilestone::run($project, ['title' => 'V1.1.1'], $owner);
        $grandChild->update(['parent_id' => $child->id]);

        $issue = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Ticket',
        ]);
        $issue->update(['milestone_id' => $child->id]);

        DeleteMilestone::run($child, $owner);

        $this->assertDatabaseMissing('milestones', ['id' => $child->id]);
        // The grand-child climbs to the deleted milestone's parent.
        $this->assertDatabaseHas('milestones', [
            'id' => $grandChild->id,
            'parent_id' => $root->id,
        ]);
        // The issue is detached, never deleted.
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'milestone_id' => null,
        ]);
    }

    public function test_delete_journals_the_loss_inventory(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, [
            'title' => 'V1',
            'description' => 'Première version',
            'start_date' => '2026-01-10',
            'target_date' => '2026-02-20',
        ], $owner);

        $issue = CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => 'Ticket',
        ]);
        $issue->update(['milestone_id' => $milestone->id]);

        DeleteMilestone::run($milestone, $owner);

        // The journal is written right before the delete: the subject FK is
        // nulled on delete by the database, so the event is found through
        // its project and its type.
        $event = AuditEvent::query()
            ->where('project_id', $project->id)
            ->where('type', 'milestone.deleted')
            ->firstOrFail();

        $this->assertSame('V1', $event->changes['title']);
        $this->assertSame('Première version', $event->changes['description']);
        $this->assertSame('2026-01-10', $event->changes['start_date']);
        $this->assertSame('2026-02-20', $event->changes['target_date']);
        $this->assertSame(1, $event->changes['issues_count']);
    }

    // ── Clôture ─────────────────────────────────────────────────────

    public function test_close_sets_completed_at_and_journals(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)
            ->post(route('milestones.close', $milestone))
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'completed_at' => now()->toDateTimeString(),
        ]);

        $event = AuditEvent::query()
            ->where('milestone_id', $milestone->id)
            ->where('type', 'milestone.closed')
            ->firstOrFail();

        $this->assertNull($event->changes['completed_at']['before']);
        $this->assertNotNull($event->changes['completed_at']['after']);
    }

    public function test_close_of_an_already_closed_milestone_journals_nothing(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)->post(route('milestones.close', $milestone))->assertRedirect();
        $eventsAfterFirst = AuditEvent::query()->where('milestone_id', $milestone->id)->count();

        $this->actingAs($owner)->post(route('milestones.close', $milestone))->assertRedirect();

        $this->assertSame(
            $eventsAfterFirst,
            AuditEvent::query()->where('milestone_id', $milestone->id)->count(),
        );
    }

    public function test_close_with_a_future_start_date_is_refused(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, [
            'title' => 'V1',
            'start_date' => now()->addYear()->toDateString(),
        ], $owner);

        $this->actingAs($owner)
            ->post(route('milestones.close', $milestone))
            ->assertSessionHasErrors('completed_at');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'completed_at' => null,
        ]);
    }

    // ── Retard ──────────────────────────────────────────────────────

    public function test_overdue_requires_a_passed_target_date_without_completion(): void
    {
        [$owner, , $project] = $this->setupWorkspace();

        $late = CreateMilestone::run($project, [
            'title' => 'En retard',
            'target_date' => now()->subWeek()->toDateString(),
        ], $owner);
        $finished = CreateMilestone::run($project, [
            'title' => 'Terminée',
            'target_date' => now()->subWeek()->toDateString(),
        ], $owner);
        $finished->forceFill(['completed_at' => now()])->save();
        $upcoming = CreateMilestone::run($project, [
            'title' => 'À venir',
            'target_date' => now()->addWeek()->toDateString(),
        ], $owner);
        $undated = CreateMilestone::run($project, ['title' => 'Sans date'], $owner);

        $this->assertTrue($late->refresh()->isOverdue());
        $this->assertFalse($finished->refresh()->isOverdue());
        $this->assertFalse($upcoming->refresh()->isOverdue());
        $this->assertFalse($undated->refresh()->isOverdue());

        $overdueIds = Milestone::query()->overdue()->pluck('id')->all();

        $this->assertSame([$late->id], $overdueIds);
    }

    // ── Liste ───────────────────────────────────────────────────────

    public function test_list_returns_the_project_milestones_only(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);

        $inProject = CreateMilestone::run($project, ['title' => 'V1'], $owner);
        CreateMilestone::run($otherProject, ['title' => 'Autre projet'], $owner);

        $response = $this->actingAs($owner)
            ->get(route('milestones.index', $project))
            ->assertOk()
            ->assertJsonPath('milestones.0.id', $inProject->id)
            ->assertJsonPath('milestones.0.title', 'V1');

        $this->assertCount(1, $response->json('milestones'));
    }

    public function test_list_respects_the_guest_perimeter(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);
        CreateMilestone::run($project, ['title' => 'V1'], $owner);

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

        // In perimeter: readable.
        $this->actingAs($guest)
            ->get(route('milestones.index', $project))
            ->assertOk();

        // Out of perimeter: fails like a non-existent project.
        $this->actingAs($guest)
            ->get(route('milestones.index', $outOfScope))
            ->assertNotFound();
    }
}
