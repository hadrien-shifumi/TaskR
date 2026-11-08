<?php

namespace Tests\Feature;

use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\SetMilestoneParent;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneHierarchyTest extends TestCase
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

    public function test_set_parent_journals_parent_set(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $parent = CreateMilestone::run($project, ['title' => 'V1'], $owner);
        $child = CreateMilestone::run($project, ['title' => 'V1.1'], $owner);

        $this->actingAs($owner)
            ->post(route('milestones.parent', $child), ['parent_id' => $parent->id])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'id' => $child->id,
            'parent_id' => $parent->id,
        ]);

        $event = AuditEvent::query()
            ->where('milestone_id', $child->id)
            ->where('type', 'milestone.parent_set')
            ->firstOrFail();

        $this->assertNull($event->changes['parent_id']['before']);
        $this->assertSame($parent->id, $event->changes['parent_id']['after']);
    }

    public function test_remove_parent_journals_parent_removed(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $parent = CreateMilestone::run($project, ['title' => 'V1'], $owner);
        $child = CreateMilestone::run($project, ['title' => 'V1.1'], $owner);
        SetMilestoneParent::run($child, $parent->id, $owner);

        $this->actingAs($owner)
            ->post(route('milestones.parent', $child), ['parent_id' => null])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'id' => $child->id,
            'parent_id' => null,
        ]);

        $event = AuditEvent::query()
            ->where('milestone_id', $child->id)
            ->where('type', 'milestone.parent_removed')
            ->firstOrFail();

        $this->assertSame($parent->id, $event->changes['parent_id']['before']);
        $this->assertNull($event->changes['parent_id']['after']);
    }

    public function test_self_parent_is_refused(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $this->actingAs($owner)
            ->post(route('milestones.parent', $milestone), ['parent_id' => $milestone->id])
            ->assertStatus(422);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'parent_id' => null,
        ]);
    }

    public function test_two_node_cycle_is_refused(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $a = CreateMilestone::run($project, ['title' => 'A'], $owner);
        $b = CreateMilestone::run($project, ['title' => 'B'], $owner);
        SetMilestoneParent::run($b, $a->id, $owner);

        // A -> B would close the cycle B -> A.
        $this->actingAs($owner)
            ->post(route('milestones.parent', $a), ['parent_id' => $b->id])
            ->assertStatus(422);

        $this->assertDatabaseHas('milestones', [
            'id' => $a->id,
            'parent_id' => null,
        ]);
    }

    public function test_three_node_cycle_is_refused(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $a = CreateMilestone::run($project, ['title' => 'A'], $owner);
        $b = CreateMilestone::run($project, ['title' => 'B'], $owner);
        $c = CreateMilestone::run($project, ['title' => 'C'], $owner);
        SetMilestoneParent::run($b, $a->id, $owner);
        SetMilestoneParent::run($c, $b->id, $owner);

        // C -> A would close the chain A -> B -> C -> A: the walk must
        // traverse the whole chain, not just the direct parent.
        $this->actingAs($owner)
            ->post(route('milestones.parent', $a), ['parent_id' => $c->id])
            ->assertStatus(422);

        $this->assertDatabaseHas('milestones', [
            'id' => $a->id,
            'parent_id' => null,
        ]);
    }

    public function test_parent_from_another_project_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Appli mobile']);

        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Etrangère'], $owner);

        $this->actingAs($owner)
            ->post(route('milestones.parent', $milestone), ['parent_id' => $foreign->id])
            ->assertStatus(422);

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'parent_id' => null,
        ]);
    }

    public function test_parent_from_another_workspace_is_refused(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $milestone = CreateMilestone::run($project, ['title' => 'V1'], $owner);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Ailleurs']);
        $foreign = CreateMilestone::run($otherProject, ['title' => 'Etrangère'], $otherOwner);
        app(WorkspaceContext::class)->set(
            Workspace::query()->where('name', 'WS')->firstOrFail()
        );

        $this->actingAs($owner)
            ->post(route('milestones.parent', $milestone), ['parent_id' => $foreign->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'parent_id' => null,
        ]);
    }

    public function test_ancestor_chain_lists_titles_root_first(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $a = CreateMilestone::run($project, ['title' => 'A'], $owner);
        $b = CreateMilestone::run($project, ['title' => 'B'], $owner);
        $c = CreateMilestone::run($project, ['title' => 'C'], $owner);
        SetMilestoneParent::run($b, $a->id, $owner);
        SetMilestoneParent::run($c, $b->id, $owner);

        $chain = $c->ancestorChain();

        $this->assertSame([
            ['id' => $a->id, 'title' => 'A'],
            ['id' => $b->id, 'title' => 'B'],
        ], $chain);
    }

    public function test_ancestor_ids_detect_cycles_through_the_trait(): void
    {
        [$owner, , $project] = $this->setupWorkspace();
        $a = CreateMilestone::run($project, ['title' => 'A'], $owner);
        $b = CreateMilestone::run($project, ['title' => 'B'], $owner);
        SetMilestoneParent::run($b, $a->id, $owner);

        $this->assertSame([$a->id], $b->ancestorIds());
        $this->assertSame([], $a->ancestorIds());
    }
}
