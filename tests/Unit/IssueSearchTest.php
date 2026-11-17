<?php

namespace Tests\Unit;

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\IssueSearch;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueSearchTest extends TestCase
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

    private function searchedIds(string $search): array
    {
        $query = Issue::query();
        IssueSearch::apply($query, $search);

        return $query->pluck('id')->all();
    }

    public function test_the_title_matches_anywhere_case_insensitively(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Rapport annuel']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Compte client']);

        expect($this->searchedIds('ANNUEL'))->toBe([$issue->id]);
    }

    public function test_a_complete_identifier_matches(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans mot cle']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Autre ticket']);

        expect($this->searchedIds($issue->id))->toBe([$issue->id]);
    }

    public function test_an_identifier_prefix_of_seven_characters_matches(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans mot cle']);

        expect($this->searchedIds(substr(strtoupper($issue->id), 0, 7)))->toBe([$issue->id]);
    }

    public function test_an_identifier_prefix_shorter_than_seven_characters_is_ignored(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans mot cle']);

        $prefix = substr(strtoupper(Issue::query()->value('id')), 0, 6);

        expect($this->searchedIds($prefix))->toBe([]);
    }

    public function test_the_title_branch_stays_raw_like_the_historical_search(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();
        $alpha = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Alpha']);

        // The title branch keeps its LIKE semantics — "a%" matches any
        // title containing an "a" (historical behavior, never escaped).
        expect($this->searchedIds('a%'))->toBe([$alpha->id]);

        // A text carrying a wildcard is never treated as an identifier:
        // the identifier branch stays silent.
        $other = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Zzz sans rapport']);
        $prefix = substr(strtoupper($other->id), 0, 7).'%';

        expect($this->searchedIds($prefix))->toBe([]);
    }
}
