<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ListIssues;
use App\Actions\Issues\MoveIssue;
use App\Actions\Issues\SetIssueParent;
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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IssueMoveCascadeTest extends TestCase
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
     * A three-level tree: parent → child → grandchild, everything in backlog.
     *
     * @return array{parent: Issue, child: Issue, grandchild: Issue}
     */
    private function seedThreeLevels(Project $project, ?Status $status = null): array
    {
        $parent = $this->createIssue($project, 'Parent', $status);
        $child = $this->createIssue($project, 'Child', $status);
        $grandchild = $this->createIssue($project, 'Grandchild', $status);
        SetIssueParent::run($child, $parent->id);
        SetIssueParent::run($grandchild, $child->id);

        return ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild];
    }

    // ── 1. La cascade entraîne toute la descendance ──────────────────

    public function test_cascade_moves_the_whole_active_subtree_over_three_levels(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($project);

        MoveIssue::run($parent, $statuses['in_progress']->id, $owner, true);

        $this->assertSame($statuses['in_progress']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['in_progress']->id, $child->fresh()->status_id);
        $this->assertSame($statuses['in_progress']->id, $grandchild->fresh()->status_id);
    }

    public function test_without_cascade_only_the_parent_moves(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($project);

        MoveIssue::run($parent, $statuses['in_progress']->id, $owner);

        $this->assertSame($statuses['in_progress']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $grandchild->fresh()->status_id);
    }

    public function test_cascade_skips_descendants_already_in_the_target_status(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child', $statuses['in_progress']);
        $grandchild = $this->createIssue($project, 'Grandchild');
        SetIssueParent::run($child, $parent->id);
        SetIssueParent::run($grandchild, $child->id);

        MoveIssue::run($parent, $statuses['in_progress']->id, $owner, true);

        $this->assertSame($statuses['in_progress']->id, $child->fresh()->status_id);

        // Only the issues that actually moved journal a status change.
        $moved = AuditEvent::query()
            ->where('type', AuditEventType::IssueStatusChanged->value)
            ->pluck('issue_id');

        $this->assertEqualsCanonicalizing([$parent->id, $grandchild->id], $moved->all());
    }

    public function test_cascade_never_touches_archived_descendants(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        SetIssueParent::run($child, $parent->id);

        // An archived child left the parent's scope: it never follows.
        $child->update(['archived_at' => now()]);

        MoveIssue::run($parent, $statuses['in_progress']->id, $owner, true);

        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);
        $this->assertNotNull($child->fresh()->archived_at);
    }

    // ── 2. Le garde-fou sur le statut Terminé ────────────────────────

    public function test_move_to_done_with_an_open_descendant_is_refused(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($project);

        try {
            MoveIssue::run($parent, $statuses['done']->id, $owner);
            $this->fail('The move should have been refused.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString($child->title, $exception->getMessage());
        }

        // Nothing moved: the parent and its descendants keep their statuses.
        $this->assertSame($statuses['backlog']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);
    }

    public function test_move_to_done_counts_a_cancelled_descendant_as_closed(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child', $statuses['cancelled']);
        SetIssueParent::run($child, $parent->id);

        MoveIssue::run($parent, $statuses['done']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
        // A cancelled child counts as closed — it is not dragged along.
        $this->assertSame($statuses['cancelled']->id, $child->fresh()->status_id);
    }

    public function test_move_to_done_ignores_archived_descendants(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        SetIssueParent::run($child, $parent->id);

        // An archived open child no longer blocks: it left the scope.
        $child->update(['archived_at' => now()]);

        MoveIssue::run($parent, $statuses['done']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);
    }

    public function test_a_childless_parent_still_moves_to_done_directly(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Direct');

        MoveIssue::run($parent, $statuses['done']->id, $owner);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    // ── 3. L'auto-complétion ne déplace jamais le parent en cascade ──

    public function test_cascade_to_a_second_done_status_never_relocates_the_parent(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        // A second done status, after the default one: the auto-completion
        // targets the first — the cascade must not relocate the parent there.
        $secondDone = Status::create([
            'workspace_id' => $workspace->id,
            'name' => 'Done bis',
            'category' => 'done',
            'position' => 99,
        ]);

        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        SetIssueParent::run($child, $parent->id);
        $parent->forceFill(['auto_complete_children' => true])->save();

        MoveIssue::run($parent, $secondDone->id, $owner, true);

        // The parent stays where the user moved it: its own move is manual,
        // the auto-completion only climbs above it.
        $this->assertSame($secondDone->id, $parent->fresh()->status_id);
        $this->assertSame($secondDone->id, $child->fresh()->status_id);
        $this->assertNotSame($statuses['done']->id, $parent->fresh()->status_id);
    }

    public function test_cascade_to_done_satisfies_the_done_guard(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($project);

        // The guard would refuse this move alone; the cascade closes the
        // whole subtree first, so the parent follows.
        MoveIssue::run($parent, $statuses['done']->id, $owner, true);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['done']->id, $child->fresh()->status_id);
    }

    // ── 4. Le périmètre des invités ──────────────────────────────────

    public function test_guest_cascade_ignores_subtrees_outside_their_perimeter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();

        $guest = User::factory()->create();
        Membership::create(['user_id' => $guest->id, 'workspace_id' => $workspace->id, 'role' => 'guest']);
        ProjectMember::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $guest->id,
            'role' => 'member',
        ]);

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Autre']);

        // The parent sits in the guest's perimeter, its child outside.
        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($otherProject, 'Hidden child');
        SetIssueParent::run($child, $parent->id);

        // The cascade only covers the perimeter: the hidden child is ignored,
        // and it does not block the Done guard either.
        MoveIssue::run($parent, $statuses['done']->id, $guest, true);

        $this->assertSame($statuses['done']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);

        // A full member sees the whole tree: the same move is refused.
        $parent->update(['status_id' => $statuses['backlog']->id]);

        try {
            MoveIssue::run($parent, $statuses['done']->id, $owner);
            $this->fail('The move should have been refused.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    // ── 5. Le point d'entrée web et le résumé des cartes ─────────────

    public function test_moving_over_http_with_cascade_children_moves_the_subtree(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        ['parent' => $parent, 'child' => $child] = $this->seedThreeLevels($project);

        $this->actingAs($owner)
            ->post("/issues/{$parent->id}/move", [
                'status_id' => $statuses['in_progress']->id,
                'cascade_children' => true,
            ])
            ->assertRedirect();

        $this->assertSame($statuses['in_progress']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['in_progress']->id, $child->fresh()->status_id);
    }

    public function test_a_same_status_move_is_a_noop_even_with_cascade(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        // The parent sits in the target status, its descendants in backlog.
        $parent = $this->createIssue($project, 'Parent', $statuses['in_progress']);
        $child = $this->createIssue($project, 'Child');
        $grandchild = $this->createIssue($project, 'Grandchild');
        SetIssueParent::run($child, $parent->id);
        SetIssueParent::run($grandchild, $child->id);

        MoveIssue::run($parent, $statuses['in_progress']->id, $owner, true);

        // A move to the current status is a full no-op: no cascade, no event.
        $this->assertSame($statuses['in_progress']->id, $parent->fresh()->status_id);
        $this->assertSame($statuses['backlog']->id, $child->fresh()->status_id);
        $this->assertSame(0, AuditEvent::query()
            ->where('type', AuditEventType::IssueStatusChanged->value)
            ->count());
    }

    public function test_every_moved_issue_journals_a_status_changed_event(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        ['parent' => $parent, 'child' => $child, 'grandchild' => $grandchild] = $this->seedThreeLevels($project);

        MoveIssue::run($parent, $statuses['done']->id, $owner, true);

        foreach ([$parent, $child, $grandchild] as $issue) {
            $event = AuditEvent::query()
                ->where('type', AuditEventType::IssueStatusChanged->value)
                ->where('issue_id', $issue->id)
                ->firstOrFail();

            $this->assertSame($owner->id, $event->actor_id);
            $this->assertSame(
                ['status' => ['before' => $statuses['backlog']->name, 'after' => $statuses['done']->name]],
                $event->changes,
            );
        }
    }

    public function test_the_descendant_summary_counts_active_descendants_per_status(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspaceWithStatuses();
        $parent = $this->createIssue($project, 'Parent');
        $child = $this->createIssue($project, 'Child');
        $grandchild = $this->createIssue($project, 'Grandchild', $statuses['done']);
        SetIssueParent::run($child, $parent->id);
        SetIssueParent::run($grandchild, $child->id);

        // An archived child never counts.
        $archived = $this->createIssue($project, 'Archived', $statuses['in_progress']);
        SetIssueParent::run($archived, $parent->id);
        $archived->update(['archived_at' => now()]);

        $result = ListIssues::run($workspace, [], null, 0, $owner);

        $card = collect($result['issues'])->firstWhere('id', $parent->id);

        $this->assertSame(['total' => 2, 'byStatus' => [
            $statuses['backlog']->id => 1,
            $statuses['done']->id => 1,
        ]], $card['descendants']);
    }
}
