<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueDependencyPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupWorkspace(): array
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

    private function makeIssue(\App\Models\Workspace $workspace, Project $project, string $title, ?Status $status = null): Issue
    {
        return CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => $title,
            ...($status === null ? [] : ['status_id' => $status->id]),
        ]);
    }

    public function test_issue_page_exposes_dependencies(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $a = $this->makeIssue($workspace, $project, 'A', $statuses['backlog']);
        $b = $this->makeIssue($workspace, $project, 'B', $statuses['in_progress']);
        $c = $this->makeIssue($workspace, $project, 'C', $statuses['done']);
        $d = $this->makeIssue($workspace, $project, 'D', $statuses['backlog']);
        $this->makeIssue($workspace, $project, 'E', $statuses['backlog'])
            ->update(['archived_at' => now()]);

        // U is fully unblocked: every blocker is done.
        $u = $this->makeIssue($workspace, $project, 'U', $statuses['backlog']);
        $c2 = $this->makeIssue($workspace, $project, 'C2', $statuses['done']);
        $u->blockedBy()->sync([$c2->id]);

        $a->blockedBy()->sync([$b->id, $c->id]);

        $this->actingAs($owner)
            ->get("/issues/{$a->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Show')
                ->has('issue.blockedBy', 2)
                ->has('issue.blocks', 0)
                ->where('issue.blockedBy.0.statusName', 'In Progress')
                ->where('issue.blockedBy.0.statusCategory', 'in_progress')
                ->where('issue.isBlocked', true)
                ->where('issue.isUnblockable', false)
                // Candidates: workspace issues minus self, minus already
                // linked blockers, minus archived — title order.
                ->has('dependencyCandidates', 3)
                ->has('dependencyCandidates.0', fn (AssertableInertia $candidate) => $candidate
                    ->where('id', $c2->id)
                    ->where('title', 'C2')
                    ->etc())
                ->where('dependencyCandidates.1.id', $d->id)
                ->where('dependencyCandidates.2.id', $u->id));

        // Mirror view: B sees A in its "blocks" list, with A's blocker ids
        // so the edge can be re-synced from that side.
        $this->actingAs($owner)
            ->get("/issues/{$b->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issue.blockedBy', 0)
                ->has('issue.blocks', 1)
                ->where('issue.blocks.0.id', $a->id)
                ->has('issue.blocks.0.blockerIds', 2)
                ->where('issue.blocks.0.blockerIds', fn ($ids): bool => $ids->contains($b->id) && $ids->contains($c->id))
                ->where('issue.isBlocked', false)
                ->where('issue.isUnblockable', false));

        // U has only done blockers: unblockable flows through the page.
        $this->actingAs($owner)
            ->get("/issues/{$u->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issue.blockedBy', 1)
                ->where('issue.blockedBy.0.statusCategory', 'done')
                ->where('issue.isBlocked', false)
                ->where('issue.isUnblockable', true));
    }

    public function test_issue_page_dependency_candidates_respect_guest_perimeter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Hors perimetre']);

        $x = $this->makeIssue($workspace, $project, 'X', $statuses['backlog']);
        $y = $this->makeIssue($workspace, $project, 'Y', $statuses['backlog']);
        $this->makeIssue($workspace, $otherProject, 'Z', $statuses['backlog']);

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
            ->get("/issues/{$x->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('dependencyCandidates', 1)
                ->where('dependencyCandidates.0.id', $y->id));
    }

    public function test_board_modal_exposes_dependencies(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupWorkspace();

        $a = $this->makeIssue($workspace, $project, 'A', $statuses['backlog']);
        $b = $this->makeIssue($workspace, $project, 'B', $statuses['in_progress']);
        $d = $this->makeIssue($workspace, $project, 'D', $statuses['backlog']);
        $a->blockedBy()->sync([$b->id]);

        $this->actingAs($owner)
            ->get(route('projects.board', ['project' => $project->id, 'issue' => $a->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->has('issueDetail.issue.blockedBy', 1)
                ->where('issueDetail.issue.blockedBy.0.id', $b->id)
                ->where('issueDetail.issue.isBlocked', true)
                ->where('issueDetail.issue.isUnblockable', false)
                ->has('issueDetail.dependencyCandidates', 1)
                ->where('issueDetail.dependencyCandidates.0.id', $d->id));
    }
}
