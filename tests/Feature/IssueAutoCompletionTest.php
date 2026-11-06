<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Issues\PropagateParentCompletion;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueAutoCompletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Owner, workspace (default statuses), project and the statuses keyed
     * by category.
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

    /**
     * Link the issue to its parent and switch the parent's auto-completion on.
     */
    private function enableAutoCompletion(Issue $parent, Issue $child): void
    {
        $child->update(['parent_id' => $parent->id]);
        $parent->forceFill(['auto_complete_children' => true])->save();
    }

    // ── 1. Bascule du parent quand tous les enfants sont terminés ────

    public function test_parent_with_auto_completion_follows_the_first_done_status_when_all_children_are_done(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $this->enableAutoCompletion($parent, $child1);
        $child2->update(['parent_id' => $parent->id]);

        MoveIssue::run($child1, $statuses['done']->id, $owner);
        $this->assertNotSame($statuses['done']->id, $parent->fresh()->status_id);

        MoveIssue::run($child2, $statuses['done']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    public function test_parent_without_the_option_is_never_moved_by_its_children(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $child2->update(['parent_id' => $parent->id]);
        $child1->update(['parent_id' => $parent->id]);

        MoveIssue::run($child1, $statuses['done']->id, $owner);
        MoveIssue::run($child2, $statuses['done']->id, $owner);

        $this->assertNotSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    public function test_parent_stays_open_while_one_child_is_still_open(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $this->enableAutoCompletion($parent, $child1);
        $child2->update(['parent_id' => $parent->id]);

        MoveIssue::run($child1, $statuses['done']->id, $owner);

        $this->assertNotSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    // ── 2. Tout annulé / cas mixte ───────────────────────────────────

    public function test_parent_follows_the_cancelled_status_when_all_children_are_cancelled(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $this->enableAutoCompletion($parent, $child1);
        $child2->update(['parent_id' => $parent->id]);

        MoveIssue::run($child1, $statuses['cancelled']->id, $owner);
        MoveIssue::run($child2, $statuses['cancelled']->id, $owner);

        $this->assertSame($statuses['cancelled']->id, $parent->fresh()->status_id);
    }

    public function test_mixed_done_and_cancelled_children_lead_the_parent_to_the_first_done_status(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $this->enableAutoCompletion($parent, $child1);
        $child2->update(['parent_id' => $parent->id]);

        MoveIssue::run($child1, $statuses['done']->id, $owner);
        MoveIssue::run($child2, $statuses['cancelled']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    // ── 3. Réouverture : le parent n'est jamais rouvert ──────────────

    public function test_reopening_a_child_never_reopens_the_parent(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $this->enableAutoCompletion($parent, $child);

        MoveIssue::run($child, $statuses['done']->id, $owner);
        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);

        MoveIssue::run($child, $statuses['backlog']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    // ── 4. Cascade multi-niveaux ─────────────────────────────────────

    public function test_completion_cascades_up_the_hierarchy(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $grandParent = $this->createIssue($project, 'Grand-parent');
        $parent = $this->createIssue($project, 'Parent');
        $parent->update(['parent_id' => $grandParent->id]);
        $parent->forceFill(['auto_complete_children' => true])->save();
        $grandParent->forceFill(['auto_complete_children' => true])->save();

        $child1 = $this->createIssue($project, 'Child 1');
        $child2 = $this->createIssue($project, 'Child 2');
        $this->enableAutoCompletion($parent, $child1);
        $child2->update(['parent_id' => $parent->id]);

        MoveIssue::run($child1, $statuses['done']->id, $owner);
        MoveIssue::run($child2, $statuses['done']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['done']->id, $grandParent->fresh()->status_id);
    }

    // ── 5. Garde-fous ────────────────────────────────────────────────

    public function test_completion_is_a_silent_noop_without_terminal_status_in_the_workspace(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        // A workspace may have no terminal status at all (the owner deleted
        // them while unused): the children can then never reach one, and the
        // propagation must stay a silent no-op.
        Status::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('category', ['done', 'cancelled'])
            ->delete();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $this->enableAutoCompletion($parent, $child);

        $inProgress = Status::query()->where('workspace_id', $workspace->id)
            ->where('category', 'in_progress')->firstOrFail();

        MoveIssue::run($child, $inProgress->id, $owner);

        $this->assertSame($inProgress->id, $child->fresh()->status_id);
        $this->assertNotSame($inProgress->id, $parent->fresh()->status_id);
    }

    public function test_archived_children_do_not_block_the_parent_completion(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $done = $this->createIssue($project, 'Done child', $statuses['done']);
        $abandoned = $this->createIssue($project, 'Abandoned child');
        $this->enableAutoCompletion($parent, $done);

        // An archived child left the parent's scope: it no longer blocks.
        $abandoned->update(['parent_id' => $parent->id, 'archived_at' => now()]);

        PropagateParentCompletion::run($done, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    public function test_a_parent_without_active_children_never_completes_on_its_own(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $onlyChild = $this->createIssue($project, 'Only child');
        $this->enableAutoCompletion($parent, $onlyChild);

        // Every child archived: "all children" is vacuously true — the parent
        // must not complete for that.
        $onlyChild->update(['parent_id' => $parent->id, 'archived_at' => now()]);

        PropagateParentCompletion::run($onlyChild, $owner);

        $this->assertNotSame($statuses['done']->id, $parent->fresh()->status_id);
        $this->assertNotSame($statuses['cancelled']->id, $parent->fresh()->status_id);
    }

    // ── 6. Option désactivée par défaut ──────────────────────────────

    public function test_auto_completion_is_disabled_by_default(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspaceWithStatuses();

        $issue = $this->createIssue($project, 'Default off');

        $this->assertFalse($issue->auto_complete_children);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'auto_complete_children' => false,
        ]);
    }

    // ── 7. Traçabilité au journal d'activité ─────────────────────────

    public function test_auto_completion_is_traced_in_the_activity_journal(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $this->enableAutoCompletion($parent, $child);

        MoveIssue::run($child, $statuses['done']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);

        $event = AuditEvent::query()
            ->where('type', AuditEventType::IssueAutoCompleted->value)
            ->firstOrFail();

        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame($parent->id, $event->issue_id);
        $this->assertSame([
            'status' => ['before' => $statuses['backlog']->name, 'after' => $statuses['done']->name],
        ], $event->changes);

        // The automatic completion is distinct from a manual status change.
        $this->assertSame(1, AuditEvent::query()
            ->where('type', AuditEventType::IssueStatusChanged->value)
            ->count());
    }

    // ── 8. Le point d'entrée web déclenche la propagation ────────────

    public function test_moving_a_child_over_http_completes_the_parent(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $this->enableAutoCompletion($parent, $child);

        $this->actingAs($owner)
            ->post("/issues/{$child->id}/move", ['status_id' => $statuses['done']->id])
            ->assertRedirect();

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
    }
}
