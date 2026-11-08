<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A workspace with one project and three issues.
     *
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
     * A guest scoped to the "Alpha" project, with an in-scope and an
     * out-of-scope issue.
     *
     * @return array{0: User, 1: Workspace, 2: Issue, 3: Issue}
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

        return [$guest, $workspace, $inScopeIssue, $outOfScopeIssue];
    }

    public function test_search_finds_issues_by_title_within_the_workspace(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Facturation']);

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => 'profil']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $issue->id)
            ->assertJsonPath('0.title', 'Page de profil')
            ->assertJsonPath('0.projectId', $project->id);
    }

    public function test_issues_of_another_workspace_are_never_returned(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);

        $otherOwner = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($otherOwner, ['name' => 'Other WS']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, $otherOwner, ['name' => 'Other project']);
        CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Page de profil']);

        // Back to the first workspace, explicitly.
        $this->withSession(['workspace_id' => $workspace->id]);

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => 'profil']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.projectId', $project->id);
    }

    public function test_search_is_scoped_to_the_guest_perimeter(): void
    {
        [$guest, , $inScopeIssue, $outOfScopeIssue] = $this->setupScopedGuest();

        $this->actingAs($guest)
            ->getJson(route('issues.search', ['q' => 'perimetre']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $inScopeIssue->id);

        // The out-of-scope ticket is invisible even when named exactly.
        $this->actingAs($guest)
            ->getJson(route('issues.search', ['q' => $outOfScopeIssue->title]))
            ->assertOk()
            ->assertJsonCount(0);

        // …and the guest may still find its own in-scope ticket by title.
        $this->actingAs($guest)
            ->getJson(route('issues.search', ['q' => $inScopeIssue->title]))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_search_matches_an_identifier_prefix(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans mot cle']);

        $prefix = substr(strtoupper($issue->id), 0, 7);

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => $prefix]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $issue->id);
    }

    public function test_the_query_is_required_and_capped(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->getJson(route('issues.search'))
            ->assertJsonValidationErrors(['q']);

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => str_repeat('a', 501)]))
            ->assertJsonValidationErrors(['q']);

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => 'ok', 'limit' => 0]))
            ->assertJsonValidationErrors(['limit']);

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => 'ok', 'limit' => 26]))
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_the_limit_is_respected(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        foreach (['Alpha un', 'Alpha deux', 'Alpha trois'] as $title) {
            CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => $title]);
        }

        $this->actingAs($owner)
            ->getJson(route('issues.search', ['q' => 'alpha', 'limit' => 2]))
            ->assertOk()
            ->assertJsonCount(2);
    }
}
