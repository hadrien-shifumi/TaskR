<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectBoardIssueDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Project, 3: array<string, Status>}
     */
    private function setupProjectWithStatuses(): array
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

    public function test_board_without_issue_param_has_no_issue_detail(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->actingAs($owner)
            ->get(route('projects.board', $project->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                ->where('issueDetail', null));
    }

    public function test_board_with_issue_param_returns_issue_detail_payload(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Modal detail',
            'status_id' => $statuses['backlog']->id,
        ]);
        $issue->comments()->create(['author_id' => $owner->getKey(), 'body' => 'Un commentaire']);
        $issue->timeEntries()->create(['author_id' => $owner->getKey(), 'worked_at' => now()->toDateString(), 'hours' => 1.5]);

        $this->actingAs($owner)
            ->get(route('projects.board', ['project' => $project->id, 'issue' => $issue->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Board')
                // The board itself stays intact behind the modal.
                ->has('columns', 4)
                // The issue detail mirrors the Issues/Show payload.
                ->where('issueDetail.issue.id', $issue->id)
                ->where('issueDetail.issue.title', 'Modal detail')
                ->where('issueDetail.issue.status.name', 'Backlog')
                ->where('issueDetail.issue.project.name', 'Site vitrine')
                ->has('issueDetail.comments', 1)
                ->where('issueDetail.comments.0.body', 'Un commentaire')
                ->has('issueDetail.timeEntries', 1)
                ->where('issueDetail.timeEntries.0.hours', 1.5)
                ->has('issueDetail.statuses', 4)
                ->has('issueDetail.candidates', 0));
    }

    public function test_board_issue_param_unknown_issue_is_not_found(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $this->actingAs($owner)
            ->get(route('projects.board', ['project' => $project->id, 'issue' => '01AAAAAAAAAAAAAAAAAAAAAAAA']))
            ->assertNotFound();
    }

    public function test_board_issue_param_respects_guest_perimeter(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $otherProject = CreateProject::run($workspace, $owner, ['name' => 'Hors perimetre']);
        $outOfScopeIssue = CreateIssue::run($workspace, [
            'project_id' => $otherProject->id,
            'title' => 'Interdit',
            'status_id' => $statuses['backlog']->id,
        ]);

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
            ->get(route('projects.board', ['project' => $project->id, 'issue' => $outOfScopeIssue->id]))
            ->assertNotFound();
    }

    public function test_board_issue_param_of_other_workspace_is_not_found(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Autre WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Etranger']);
        $otherIssue = CreateIssue::run($otherWorkspace, [
            'project_id' => $otherProject->id,
            'title' => 'Etrangere',
        ]);

        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->get(route('projects.board', ['project' => $project->id, 'issue' => $otherIssue->id]))
            ->assertNotFound();
    }

    public function test_issue_update_redirects_back_to_caller(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Ancien titre',
            'status_id' => $statuses['backlog']->id,
        ]);

        // From the full issue page, the caller is the issue page itself.
        $this->actingAs($owner)
            ->from(route('issues.show', $issue->id))
            ->patch(route('issues.update', $issue->id), ['title' => 'Nouveau titre'])
            ->assertRedirect(route('issues.show', $issue->id));

        // From the board modal, the caller is the board with the issue param.
        $boardUrl = route('projects.board', ['project' => $project->id, 'issue' => $issue->id]);

        $this->actingAs($owner)
            ->from($boardUrl)
            ->patch(route('issues.update', $issue->id), ['title' => 'Autre titre'])
            ->assertRedirect($boardUrl);
    }

    public function test_issue_labels_redirect_back_to_caller(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Avec labels',
            'status_id' => $statuses['backlog']->id,
        ]);

        $boardUrl = route('projects.board', ['project' => $project->id, 'issue' => $issue->id]);

        $this->actingAs($owner)
            ->from($boardUrl)
            ->post(route('issues.labels', $issue->id), ['label_ids' => []])
            ->assertRedirect($boardUrl);
    }

    public function test_comment_from_board_modal_redirects_back(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Commentee',
            'status_id' => $statuses['backlog']->id,
        ]);

        $boardUrl = route('projects.board', ['project' => $project->id, 'issue' => $issue->id]);

        $this->actingAs($owner)
            ->from($boardUrl)
            ->post(route('comments.store', $issue->id), ['body' => 'Depuis la modal'])
            ->assertRedirect($boardUrl);
    }

    public function test_time_entry_from_board_modal_redirects_back(): void
    {
        [$owner, $workspace, $project, $statuses] = $this->setupProjectWithStatuses();

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Chrono',
            'status_id' => $statuses['backlog']->id,
        ]);

        $boardUrl = route('projects.board', ['project' => $project->id, 'issue' => $issue->id]);

        $this->actingAs($owner)
            ->from($boardUrl)
            ->post(route('issues.time-entries.store', $issue->id), ['hours' => 2])
            ->assertRedirect($boardUrl);
    }
}
