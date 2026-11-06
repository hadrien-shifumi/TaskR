<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Option « complétion automatique » exposée sur la fiche du parent : un
 * point d'entrée dédié active et désactive la colonne
 * auto_complete_children, trace le changement au journal d'activité, et la
 * fiche transporte l'état courant. Le signal « un enfant est ouvert »
 * accompagne les cartes des trois surfaces (liste, kanban transverse,
 * board projet).
 */
class IssueAutoCompletionToggleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Owner, workspace (statuts par défaut), projet et statuts indexés
     * par catégorie.
     *
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspaceWithStatuses(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $statuses = [];
        foreach ($workspace->statuses as $status) {
            $statuses[$status->category->value] = $status;
        }

        return [$owner, $workspace, $project, $statuses];
    }

    private function createIssue(Project $project, string $title, ?Status $status = null): Issue
    {
        return CreateIssue::run(app(WorkspaceContext::class)->getOrFail(), [
            'project_id' => $project->id,
            'title' => $title,
            'status_id' => $status?->id,
        ]);
    }

    /** Guest scoped to the given project. */
    private function createGuestScopedTo(Workspace $workspace, Project $project): User
    {
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

        return $guest;
    }

    // ── 1. Le point d'entrée active et désactive l'option ────────────

    public function test_toggle_enables_auto_completion_and_journals_the_activation(): void
    {
        [$owner] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue(app(WorkspaceContext::class)->getOrFail()->projects->first(), 'Parent');

        $this->actingAs($owner)
            ->post(route('issues.auto-complete', $parent), ['enabled' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $parent->id,
            'auto_complete_children' => true,
        ]);

        $event = AuditEvent::query()
            ->where('type', AuditEventType::IssueAutoCompletionEnabled->value)
            ->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($parent->id, $event->issue_id);
    }

    public function test_toggle_disables_auto_completion_and_journals_the_deactivation(): void
    {
        [$owner] = $this->setupWorkspaceWithStatuses();
        $project = app(WorkspaceContext::class)->getOrFail()->projects->first();
        $parent = $this->createIssue($project, 'Parent');
        $parent->forceFill(['auto_complete_children' => true])->save();

        $this->actingAs($owner)
            ->post(route('issues.auto-complete', $parent), ['enabled' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $parent->id,
            'auto_complete_children' => false,
        ]);

        // forceFill does not journal: only the deactivation is traced here.
        $this->assertSame(0, AuditEvent::query()
            ->where('type', AuditEventType::IssueAutoCompletionEnabled->value)->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('type', AuditEventType::IssueAutoCompletionDisabled->value)
            ->where('issue_id', $parent->id)
            ->count());
    }

    public function test_toggling_the_same_value_journals_nothing(): void
    {
        [$owner] = $this->setupWorkspaceWithStatuses();
        $project = app(WorkspaceContext::class)->getOrFail()->projects->first();
        $parent = $this->createIssue($project, 'Parent');

        // The option already defaults to false: a no-op toggle journals nothing.
        $this->actingAs($owner)
            ->post(route('issues.auto-complete', $parent), ['enabled' => false])
            ->assertRedirect();

        $this->assertSame(0, AuditEvent::query()
            ->whereIn('type', [
                AuditEventType::IssueAutoCompletionEnabled->value,
                AuditEventType::IssueAutoCompletionDisabled->value,
            ])->count());
    }

    // ── 2. Garde-fous du point d'entrée ──────────────────────────────

    public function test_toggle_rejects_a_non_boolean_value(): void
    {
        [$owner] = $this->setupWorkspaceWithStatuses();
        $project = app(WorkspaceContext::class)->getOrFail()->projects->first();
        $parent = $this->createIssue($project, 'Parent');

        $this->actingAs($owner)
            ->post(route('issues.auto-complete', $parent), ['enabled' => 'maybe'])
            ->assertSessionHasErrors('enabled');

        $this->assertFalse($parent->fresh()->auto_complete_children);
    }

    public function test_toggle_is_forbidden_outside_the_perimeter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');

        // A foreign workspace member: the issue reads as non-existent.
        $stranger = User::factory()->create();
        CreateWorkspace::run($stranger, ['name' => 'Elsewhere']);

        $this->actingAs($stranger)
            ->post(route('issues.auto-complete', $parent), ['enabled' => true])
            ->assertNotFound();

        // A guest scoped to another project: out of perimeter, 404.
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Hors périmètre']);
        $guest = $this->createGuestScopedTo($workspace, $otherProject);

        $this->actingAs($guest)
            ->post(route('issues.auto-complete', $parent), ['enabled' => true])
            ->assertNotFound();

        $this->assertFalse($parent->fresh()->auto_complete_children);
    }

    public function test_guest_within_the_perimeter_can_toggle_the_option(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');
        $guest = $this->createGuestScopedTo($workspace, $project);

        $this->actingAs($guest)
            ->post(route('issues.auto-complete', $parent), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue($parent->fresh()->auto_complete_children);
    }

    // ── 3. La fiche transporte l'état de l'option ────────────────────

    public function test_the_fiche_exposes_the_option_defaulting_to_false(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');

        $this->actingAs($owner)->get(route('issues.show', $parent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issue.autoCompleteChildren', false));

        $parent->forceFill(['auto_complete_children' => true])->save();

        $this->actingAs($owner)->get(route('issues.show', $parent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issue.autoCompleteChildren', true));
    }

    // ── 4. Le signal « un enfant est ouvert » sur les trois surfaces ──

    public function test_the_open_child_signal_rides_the_three_card_surfaces(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $child->update(['parent_id' => $parent->id]);

        // The parent is done while its child stays open: the alert signal rides
        // the parent's cards. The state is written directly: the move action
        // refuses a lone parent-to-done while a child is open.
        $parent->update(['status_id' => $statuses['done']->id]);

        // The default workspace statuses ship in a fixed order — backlog(0),
        // in_progress(1), done(2), cancelled(3) — so the done column is
        // index 2; the category assert below guards that assumption.

        // List surface.
        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issues.0.id', $parent->id)
                ->where('issues.0.hasOpenChild', true));

        // Kanban strip surface: the parent sits in the done column.
        $this->actingAs($owner)->get(route('issues.index', ['view' => 'kanban']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('projectBoards.0.columns.2.category', 'done')
                ->where('projectBoards.0.columns.2.issues.0.id', $parent->id)
                ->where('projectBoards.0.columns.2.issues.0.hasOpenChild', true));

        // Project board surface.
        $this->actingAs($owner)->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('columns.2.category', 'done')
                ->where('columns.2.issues.0.id', $parent->id)
                ->where('columns.2.issues.0.hasOpenChild', true));
    }

    public function test_the_signal_stays_off_without_an_active_child(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $child->update(['parent_id' => $parent->id]);

        // Parent still open: the raw flag is true, the badge is a front gate.
        MoveIssue::run($child, $statuses['done']->id, $owner);

        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issues.0.id', $parent->id)
                ->where('issues.0.hasOpenChild', false));
    }

    public function test_the_signal_ignores_archived_children(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $child->update(['parent_id' => $parent->id, 'archived_at' => now()]);

        MoveIssue::run($parent, $statuses['done']->id, $owner);

        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issues.0.id', $parent->id)
                ->where('issues.0.hasOpenChild', false));
    }

    public function test_the_signal_stays_off_when_every_child_is_terminal(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $child1->update(['parent_id' => $parent->id]);
        $child2->update(['parent_id' => $parent->id]);

        // The children close first: a lone parent-to-done is refused while
        // they are still open.
        MoveIssue::run($child1, $statuses['done']->id, $owner);
        MoveIssue::run($child2, $statuses['cancelled']->id, $owner);
        MoveIssue::run($parent, $statuses['done']->id, $owner);

        $this->actingAs($owner)->get(route('issues.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issues.0.id', $parent->id)
                ->where('issues.0.hasOpenChild', false));
    }
}
