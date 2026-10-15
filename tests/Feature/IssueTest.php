<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IssueTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, ['name' => 'Site vitrine']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $project, $member];
    }

    public function test_minimal_creation_uses_default_status(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/issues', ['project_id' => $project->id, 'title' => 'Page de profil'])
            ->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'title' => 'Page de profil',
            'priority' => 'none',
            'parent_id' => null,
        ]);
    }

    public function test_full_creation_with_labels_and_assignees(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        $label = CreateLabel::run($workspace, ['name' => 'front']);
        $status = $workspace->statuses()->where('category', 'in_progress')->first();

        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'UI formulaire',
                'description' => 'Champs et validation',
                'status_id' => $status->id,
                'priority' => 'high',
                'due_date' => '2026-12-01',
                'assignees' => [$member->id],
                'label_ids' => [$label->id],
            ])
            ->assertRedirect();

        $issue = $workspace->issues()->withoutGlobalScope(WorkspaceScope::class)->where('title', 'UI formulaire')->firstOrFail();

        $this->assertSame([$member->id], $issue->assignees()->pluck('users.id')->all());
        $this->assertSame([$label->id], $issue->labels()->pluck('labels.id')->all());
        $this->assertSame('high', $issue->priority->value);
    }

    public function test_child_issue_links_to_parent(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Page de profil']);
        $child = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'UI formulaire']);

        $this->actingAs($owner)
            ->post("/issues/{$child->id}/parent", ['parent_id' => $parent->id])
            ->assertRedirect();

        $this->assertSame($parent->id, $child->fresh()->parent_id);

        $this->actingAs($owner)
            ->get("/issues/{$parent->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.children.0.id', $child->id));
    }

    public function test_show_page_exposes_the_creation_date(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Dated']);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.createdAt', $issue->created_at->toISOString()));
    }

    public function test_cycle_is_rejected(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $a = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'A']);
        $b = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'B']);
        SetIssueParent::run($b, $a->id);

        // A -> B would close the cycle B -> A.
        $this->actingAs($owner)
            ->post("/issues/{$a->id}/parent", ['parent_id' => $b->id])
            ->assertStatus(422);

        // Self parenting is refused too.
        $this->actingAs($owner)
            ->post("/issues/{$a->id}/parent", ['parent_id' => $a->id])
            ->assertStatus(422);
    }

    public function test_parent_from_another_workspace_is_rejected(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Mine']);

        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $otherProject = CreateProject::run($otherWorkspace, ['name' => 'Other project']);
        $foreign = CreateIssue::run($otherWorkspace, ['project_id' => $otherProject->id, 'title' => 'Foreign']);

        // Scoped validation must reject the foreign parent id.
        $this->actingAs($other)
            ->post("/issues/{$issue->id}/parent", ['parent_id' => $foreign->id])
            ->assertNotFound();
    }

    public function test_multiple_assignees_are_managed_independently(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Fullstack']);
        $another = User::factory()->create();
        Membership::create(['user_id' => $another->id, 'workspace_id' => $workspace->id, 'role' => 'member']);

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/assignees", ['assignees' => [$member->id, $another->id]])
            ->assertRedirect();

        $this->assertSame(2, $issue->assignees()->count());

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/assignees", ['assignees' => [$member->id]])
            ->assertRedirect();

        $this->assertSame([$member->id], $issue->assignees()->pluck('users.id')->all());
    }

    public function test_status_can_move_freely_to_done(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Direct']);
        $done = $workspace->statuses()->where('category', 'done')->first();

        $this->actingAs($owner)
            ->post("/issues/{$issue->id}/move", ['status_id' => $done->id])
            ->assertRedirect();

        $this->assertSame($done->id, $issue->fresh()->status_id);
    }

    public function test_list_filters_by_status_and_overdue(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $inProgress = $workspace->statuses()->where('category', 'in_progress')->first();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Normal', 'status_id' => $inProgress->id]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Late', 'due_date' => '2020-01-01']);

        $this->actingAs($owner)
            ->get("/issues?status={$inProgress->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Normal'));

        $this->actingAs($owner)
            ->get('/issues?overdue=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Late')
                ->where('issues.0.overdue', true));
    }

    public function test_list_filters_by_title_search(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Login page fix']);

        $this->actingAs($owner)
            ->get('/issues?q=webhook')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Webhook retry')
                ->where('filters.q', 'webhook'));
    }

    public function test_list_exposes_the_unfiltered_total_alongside_the_filtered_one(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Login page fix']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Other task']);

        $this->actingAs($owner)
            ->get('/issues?q=webhook')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('total', 1)
                ->where('totalUnfiltered', 3));
    }

    public function test_issue_urls_use_ulids(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'ULID']);

        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $issue->id);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk();
    }

    public function test_web_created_issue_records_and_exposes_the_creator(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/issues', ['project_id' => $project->id, 'title' => 'Avec créateur'])
            ->assertRedirect();

        $issue = $workspace->issues()->where('title', 'Avec créateur')->firstOrFail();
        $this->assertSame($owner->id, $issue->created_by);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.creatorName', $owner->name));
    }

    public function test_issue_created_without_user_has_no_creator(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans créateur']);

        $this->assertNull($issue->created_by);

        $this->actingAs($owner)
            ->get("/issues/{$issue->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.creatorName', null));
    }

    public function test_cross_workspace_access_is_refused(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Mine']);

        $intruder = User::factory()->create();
        CreateWorkspace::run($intruder, ['name' => 'Theirs']);

        $this->actingAs($intruder)
            ->get("/issues/{$issue->id}")
            ->assertNotFound();

        $this->actingAs($intruder)
            ->patch("/issues/{$issue->id}", ['title' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_deleting_parent_makes_children_root(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $parent = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Parent']);
        $child = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Child']);
        SetIssueParent::run($child, $parent->id);

        $this->actingAs($owner)
            ->delete("/issues/{$parent->id}")
            ->assertRedirect();

        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_status_delete_refused_when_issues_use_it(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $status = $workspace->statuses()->first();
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Blocked', 'status_id' => $status->id]);

        $this->actingAs($owner)
            ->delete("/statuses/{$status->id}")
            ->assertStatus(409);
    }

    public function test_project_delete_refused_when_it_has_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Any']);

        $this->actingAs($owner)
            ->delete("/projects/{$project->id}")
            ->assertStatus(409);
    }

    public function test_labels_are_scoped_per_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        CreateLabel::run($workspace, ['name' => 'front']);

        $this->actingAs($owner)
            ->post('/labels', ['name' => 'front'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Label::withoutGlobalScope(WorkspaceScope::class)->count());
    }
}
