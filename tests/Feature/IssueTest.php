<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\ListIssues;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Mcp\Servers\TaskrServer;
use App\Mcp\Tools\ListIssues as ListIssuesTool;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class IssueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('payload', function (): array {
            /** @var TestResponse $this */
            return $this->response->toArray()['result']['structuredContent'] ?? [];
        });
    }

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

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
        $otherProject = CreateProject::run($otherWorkspace, $other, ['name' => 'Other project']);
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

    public function test_search_matches_full_ulid(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $target = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Login page fix']);

        $this->actingAs($owner)
            ->get('/issues?q='.$target->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.id', $target->id)
                ->where('issues.0.title', 'Webhook retry'));
    }

    public function test_search_matches_ulid_prefix_of_seven_chars(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $first = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);
        $second = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Login page fix']);

        // Force a shared seven-character prefix; the rest of the ULID differs.
        $prefix = '01JQZ0A';
        $first->forceFill(['id' => $prefix.'AAAAAAAAAAAAAAAAAAA'])->save();
        $second->forceFill(['id' => $prefix.'BBBBBBBBBBBBBBBBBBB'])->save();
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Other task']);

        $this->actingAs($owner)
            ->get('/issues?q='.$prefix)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('total', 2));
    }

    public function test_search_short_prefix_stays_title_only(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $target = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);
        $target->forceFill(['id' => '01JQZ0AAAAAAAAAAAAAAAAAAAA'])->save();

        // Six characters: below the seven-character threshold, so the id is
        // never consulted and only the title search applies.
        $this->actingAs($owner)
            ->get('/issues?q=01JQZ0')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0)
                ->where('total', 0));

        // Outside the ULID alphabet (I is not Crockford base32): same result.
        $this->actingAs($owner)
            ->get('/issues?q=01JQZ0IIIIII')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0)
                ->where('total', 0));
    }

    public function test_search_without_match_unchanged(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);

        $this->actingAs($owner)
            ->get('/issues?q=ZZZZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 0)
                ->where('total', 0)
                ->where('totalUnfiltered', 1));
    }

    public function test_search_lowercase_ulid_prefix_matches(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $target = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry']);
        $target->forceFill(['id' => '01JQZ0AAAAAAAAAAAAAAAAAAAA'])->save();

        $this->actingAs($owner)
            ->get('/issues?q=01jqz0a')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.id', '01JQZ0AAAAAAAAAAAAAAAAAAAA'));
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

    public function test_list_filters_by_precise_status(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $inProgress = $workspace->statuses()->where('category', 'in_progress')->first();
        $done = $workspace->statuses()->where('category', 'done')->first();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'En cours', 'status_id' => $inProgress->id]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Terminé', 'status_id' => $done->id]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Backlog']);

        $this->actingAs($owner)
            ->get("/issues?status={$done->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Terminé')
                ->where('total', 1)
                ->where('totalUnfiltered', 3));
    }

    public function test_list_label_filter_accepts_multiple_labels(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $back = CreateLabel::run($workspace, ['name' => 'back']);
        $infra = CreateLabel::run($workspace, ['name' => 'infra']);

        $done = $workspace->statuses()->where('category', 'done')->first();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Front seul', 'label_ids' => [$front->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Back seul', 'label_ids' => [$back->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Front et back', 'label_ids' => [$front->id, $back->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Infra', 'label_ids' => [$infra->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans label']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Front terminé', 'status_id' => $done->id, 'label_ids' => [$front->id]]);

        // « at least one of the selected labels ».
        $this->actingAs($owner)
            ->get("/issues?label[]={$front->id}&label[]={$back->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 4)
                ->where('total', 4));

        // AND-combination with the other filters.
        $this->actingAs($owner)
            ->get("/issues?label[]={$front->id}&label[]={$back->id}&status={$done->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Front terminé'));

        // A single label still works through the scalar param.
        $this->actingAs($owner)
            ->get("/issues?label={$infra->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Infra'));
    }

    public function test_list_filters_by_due_date_range(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tôt', 'due_date' => '2026-01-10']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Milieu', 'due_date' => '2026-02-15']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tard', 'due_date' => '2026-03-20']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans échéance']);

        $this->actingAs($owner)
            ->get('/issues?due_after=2026-02-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('total', 2));

        $this->actingAs($owner)
            ->get('/issues?due_before=2026-02-15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('total', 2));

        // Between: both bounds combined, inclusive.
        $this->actingAs($owner)
            ->get('/issues?due_after=2026-01-10&due_before=2026-02-15')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('total', 2));

        // A null due date is excluded as soon as one bound is active.
        $this->actingAs($owner)
            ->get('/issues?due_after=2000-01-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 3)
                ->where('total', 3));
    }

    public function test_issues_page_renders_status_label_and_due_date_filters(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $done = $workspace->statuses()->where('category', 'done')->first();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Filtrable']);

        // The page is client-rendered (no SSR): the filter hooks live in the
        // built Issues/Index bundle, so assert them on the compiled source.
        $pageSource = file_get_contents(resource_path('js/Pages/Issues/Index.tsx'));

        $this->assertStringContainsString('testId="filter-select-status"', $pageSource);
        $this->assertStringContainsString('testId="filter-labels"', $pageSource);
        $this->assertStringContainsString('data-test="filter-due-before"', $pageSource);
        $this->assertStringContainsString('data-test="filter-due-after"', $pageSource);

        // The status and label options are fed by the page props.
        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('statuses.2.id', $done->id)
                ->where('labels.0.id', $front->id)
                ->where('labels.0.name', 'front'));
    }

    public function test_issues_page_round_trips_new_filters_in_props(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $back = CreateLabel::run($workspace, ['name' => 'back']);
        $done = $workspace->statuses()->where('category', 'done')->first();

        $this->actingAs($owner)
            ->get("/issues?status={$done->id}&label[]={$front->id}&label[]={$back->id}&due_before=2026-01-01&due_after=2026-12-31&category=done")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status', $done->id)
                ->where('filters.label', [$front->id, $back->id])
                ->where('filters.due_before', '2026-01-01')
                ->where('filters.due_after', '2026-12-31')
                ->where('filters.category', 'done'));
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tôt', 'due_date' => '2026-01-10']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Tard', 'due_date' => '2026-03-20']);

        // A shared URL must never 422: invalid values are dropped, the page
        // renders the unfiltered list.
        $this->actingAs($owner)
            ->get('/issues?label[]=not-a-ulid&due_after=pas-une-date&due_before=2026-13-45&status=abc')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('total', 2));

        // Valid values mixed with invalid ones keep the valid part.
        $this->actingAs($owner)
            ->get('/issues?due_after=2026-02-01&due_before=pas-une-date')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Tard'));
    }

    public function test_list_sorts_by_each_column(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $backlog = $workspace->statuses()->where('category', 'backlog')->first();
        $inProgress = $workspace->statuses()->where('category', 'in_progress')->first();
        $done = $workspace->statuses()->where('category', 'done')->first();
        $site = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $other = CreateProject::run($workspace, $owner, ['name' => 'Zebra']);

        $titles = fn (array $filters): array => collect(ListIssues::run($workspace, $filters)['issues'])
            ->pluck('title')
            ->all();

        // Created oldest first, so the default (created desc) is the reverse.
        $oldest = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Créé en premier']);
        $middle = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Créé au milieu']);
        $newest = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Créé en dernier']);

        $oldest->forceFill(['created_at' => '2026-01-01 10:00:00'])->save();
        $middle->forceFill(['created_at' => '2026-01-02 10:00:00'])->save();
        $newest->forceFill(['created_at' => '2026-01-03 10:00:00'])->save();

        // Default: created_at desc, unchanged by the sort feature.
        $this->assertSame(['Créé en dernier', 'Créé au milieu', 'Créé en premier'], $titles([]));
        $this->assertSame(['Créé en dernier', 'Créé au milieu', 'Créé en premier'], $titles(['sort' => 'created', 'dir' => 'desc']));
        $this->assertSame(['Créé en premier', 'Créé au milieu', 'Créé en dernier'], $titles(['sort' => 'created', 'dir' => 'asc']));

        // Priority: enum order none < low < high < urgent.
        $middle->update(['priority' => 'none']);
        $newest->update(['priority' => 'urgent']);
        $oldest->update(['priority' => 'low']);

        $this->assertSame(['Créé au milieu', 'Créé en premier', 'Créé en dernier'], $titles(['sort' => 'priority', 'dir' => 'asc']));
        $this->assertSame(['Créé en dernier', 'Créé en premier', 'Créé au milieu'], $titles(['sort' => 'priority', 'dir' => 'desc']));

        // Due date: nulls last in both directions.
        $oldest->update(['due_date' => '2026-03-20']);
        $middle->update(['due_date' => '2026-01-10']);
        $newest->update(['due_date' => null]);

        $this->assertSame(['Créé au milieu', 'Créé en premier', 'Créé en dernier'], $titles(['sort' => 'due_date', 'dir' => 'asc']));
        $this->assertSame(['Créé en premier', 'Créé au milieu', 'Créé en dernier'], $titles(['sort' => 'due_date', 'dir' => 'desc']));

        // Status: workspace position (backlog < in progress < done).
        $oldest->update(['status_id' => $done->id]);
        $middle->update(['status_id' => $backlog->id]);
        $newest->update(['status_id' => $inProgress->id]);

        $this->assertSame(['Créé au milieu', 'Créé en dernier', 'Créé en premier'], $titles(['sort' => 'status', 'dir' => 'asc']));
        $this->assertSame(['Créé en premier', 'Créé en dernier', 'Créé au milieu'], $titles(['sort' => 'status', 'dir' => 'desc']));

        // Project: project name.
        $oldest->update(['project_id' => $other->id]);
        $middle->update(['project_id' => $site->id]);
        $newest->update(['project_id' => $other->id]);

        $this->assertSame(['Créé au milieu', 'Créé en dernier', 'Créé en premier'], $titles(['sort' => 'project', 'dir' => 'asc']));
        $this->assertSame(['Créé en dernier', 'Créé en premier', 'Créé au milieu'], $titles(['sort' => 'project', 'dir' => 'desc']));
    }

    public function test_list_paginates_at_50_per_page(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $done = $workspace->statuses()->where('category', 'done')->first();

        for ($i = 1; $i <= 51; $i++) {
            $issue = CreateIssue::run($workspace, [
                'project_id' => $project->id,
                'title' => sprintf('Ticket %02d', $i),
                'status_id' => $i === 51 ? $done->id : null,
            ]);
            $issue->forceFill(['created_at' => sprintf('2026-01-01 10:%02d:00', $i)])->save();
        }

        $this->assertSame(
            ['Ticket 51', 'Ticket 50', 'Ticket 01'],
            collect(ListIssues::run($workspace, [])['issues'])->pluck('title')->only([0, 1, 50])->values()->all(),
        );

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 50)
                ->where('issues.0.title', 'Ticket 51')
                ->where('total', 51)
                ->where('page', 1)
                ->where('perPage', 50));

        // Page 2 keeps the filters and the sort in the URL. Every ticket is
        // priority none, so the secondary created_at desc key orders them.
        $this->actingAs($owner)
            ->get('/issues?page=2&sort=priority&dir=asc&q=Ticket')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('issues.0.title', 'Ticket 01')
                ->where('total', 51)
                ->where('page', 2)
                ->where('perPage', 50)
                ->where('filters.sort', 'priority')
                ->where('filters.dir', 'asc')
                ->where('filters.q', 'Ticket'));

        // A filter narrows the total but never the page size.
        $this->actingAs($owner)
            ->get("/issues?status={$done->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 1)
                ->where('total', 1)
                ->where('totalUnfiltered', 51));
    }

    public function test_invalid_sort_and_page_values_are_ignored(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $oldest = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Créé en premier']);
        $newest = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Créé en dernier']);
        $oldest->forceFill(['created_at' => '2026-01-01 10:00:00'])->save();
        $newest->forceFill(['created_at' => '2026-01-02 10:00:00'])->save();

        // An unknown sort falls back to the default (created desc).
        $titles = fn (array $filters): array => collect(ListIssues::run($workspace, $filters)['issues'])
            ->pluck('title')
            ->all();

        $this->assertSame(['Créé en dernier', 'Créé en premier'], $titles(['sort' => 'nope']));
        $this->assertSame(['Créé en dernier', 'Créé en premier'], $titles(['sort' => 'priority', 'dir' => 'sideways']));

        // A shared URL must never 422: invalid page values land on page 1.
        foreach (['page=abc', 'page=0', 'page=-3'] as $query) {
            $this->actingAs($owner)
                ->get("/issues?{$query}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('issues', 2)
                    ->where('page', 1));
        }

        // An out-of-range page clamps to the last page.
        $this->actingAs($owner)
            ->get('/issues?page=99')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues', 2)
                ->where('page', 1));
    }

    public function test_issues_page_list_view_paginates_and_sorts(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Urgent', 'priority' => 'urgent']);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Faible', 'priority' => 'low']);

        // The page is client-rendered (no SSR): the sortable headers live in
        // the SortableHead component, the pagination bar in the page source.
        $pageSource = file_get_contents(resource_path('js/Pages/Issues/Index.tsx'));
        $sortableHeadSource = file_get_contents(resource_path('js/Pages/Issues/Index/components/sortable-head.tsx'));

        $this->assertStringContainsString('data-test={`sort-${sortKey.replace', $sortableHeadSource);
        foreach (['project', 'status', 'priority', 'due_date'] as $sortKey) {
            $this->assertStringContainsString("sortKey=\"{$sortKey}\"", $pageSource);
        }
        $this->assertStringContainsString('data-test="issues-pagination"', $pageSource);
        $this->assertStringContainsString('data-test="issues-pagination-counter"', $pageSource);

        // A page beyond the last one clamps to the last page, never 404/500.
        $this->actingAs($owner)
            ->get('/issues?sort=priority&dir=asc&page=2')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Issues/Index')
                ->has('issues', 2)
                ->where('issues.0.title', 'Faible')
                ->where('issues.1.title', 'Urgent')
                ->where('page', 1)
                ->where('perPage', 50)
                ->where('total', 2)
                ->where('filters.sort', 'priority')
                ->where('filters.dir', 'asc'));
    }

    public function test_mcp_list_issues_single_label_still_works(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $back = CreateLabel::run($workspace, ['name' => 'back']);

        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Front seul', 'label_ids' => [$front->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Back seul', 'label_ids' => [$back->id]]);
        CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Sans label']);

        $result = TaskrServer::actingAs($owner)
            ->tool(ListIssuesTool::class, ['workspace_id' => $workspace->id, 'label' => $front->id])
            ->assertOk()
            ->payload();

        $this->assertSame(['Front seul'], collect($result['issues'])->pluck('title')->all());
        $this->assertSame(1, $result['total']);
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
