<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\SavedViews\CreateSavedView;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\ProjectMember;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SavedViewTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_member_can_save_view_from_url_filters(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $status = $workspace->statuses()->where('category', 'in_progress')->first();

        $this->actingAs($member)
            ->post('/views', [
                'name' => 'Urgences front',
                'project' => $project->id,
                'status' => $status->id,
                'category' => 'in_progress',
                'priority' => 'urgent',
                'label' => [$bug->id, $front->id],
                'assignee' => $member->id,
                'overdue' => '1',
                'blocked' => 'true',
                'due_after' => '2026-01-01',
                'due_before' => '2026-12-31',
                'q' => 'paiements',
                'sort' => 'priority',
                'dir' => 'desc',
            ])
            ->assertRedirect(route('issues.index', [
                'project' => $project->id,
                'status' => $status->id,
                'category' => 'in_progress',
                'priority' => 'urgent',
                'label' => [$bug->id, $front->id],
                'assignee' => $member->id,
                'overdue' => '1',
                'blocked' => '1',
                'due_before' => '2026-12-31',
                'due_after' => '2026-01-01',
                'q' => 'paiements',
                'sort' => 'priority',
                'dir' => 'desc',
            ]))
            ->assertSessionHas('status', __('flash.saved_view.created', ['name' => 'Urgences front']));

        $view = SavedView::query()->where('name', 'Urgences front')->firstOrFail();

        $this->assertSame($workspace->id, $view->workspace_id);
        $this->assertSame($member->id, $view->created_by);
        $this->assertSame('priority', $view->sort);
        $this->assertSame('desc', $view->dir);
        $this->assertSame([
            'project' => $project->id,
            'status' => $status->id,
            'category' => 'in_progress',
            'priority' => 'urgent',
            'label' => [$bug->id, $front->id],
            'assignee' => (string) $member->id,
            'overdue' => '1',
            'blocked' => '1',
            'due_before' => '2026-12-31',
            'due_after' => '2026-01-01',
            'q' => 'paiements',
        ], $view->filters);
        $this->assertNotNull($view->summary);
    }

    public function test_invalid_filter_values_are_ignored_and_page_is_not_stored(): void
    {
        [, $workspace, , $member] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);

        $this->actingAs($member)
            ->post('/views', [
                'name' => 'Filtres pourris',
                'project' => 'abc',
                'status' => 'xyz',
                'category' => 'bogus',
                'priority' => 'zzz',
                'label' => ['not-a-ulid', $bug->id, ''],
                'assignee' => 'nope',
                'overdue' => 'maybe',
                'blocked' => '2',
                'due_after' => 'pas-une-date',
                'due_before' => '2026-13-45',
                'q' => '   ',
                'sort' => 'nope',
                'dir' => 'sideways',
                'page' => '7',
                'view' => 'kanban',
            ])
            ->assertRedirect();

        $view = SavedView::query()->where('name', 'Filtres pourris')->firstOrFail();

        // Only the well-formed label survives; everything else is dropped
        // silently — a shared URL never turns into an error, and the page
        // number or board mode never enter the saved definition.
        $this->assertSame(['label' => $bug->id], $view->filters);
        $this->assertNull($view->sort);
        $this->assertNull($view->dir);
    }

    public function test_saved_view_name_is_unique_per_workspace(): void
    {
        [, $workspace, , $member] = $this->setupWorkspace();

        CreateSavedView::run($workspace, $member, ['name' => 'Urgences', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($member)
            ->post('/views', ['name' => 'Urgences'])
            ->assertSessionHasErrors('name');

        // The same name is fine in another workspace.
        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->actingAs($other)
            ->post('/views', ['name' => 'Urgences'])
            ->assertRedirect();

        $this->assertSame(2, SavedView::withoutGlobalScope(WorkspaceScope::class)->count());
    }

    public function test_user_without_workspace_membership_cannot_save_a_view(): void
    {
        [, $workspace] = $this->setupWorkspace();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post('/views', ['name' => 'Intrusion'])
            ->assertForbidden();

        $this->assertSame(0, SavedView::withoutGlobalScope(WorkspaceScope::class)->count());
    }

    public function test_guest_can_save_a_view(): void
    {
        [, $workspace] = $this->setupWorkspace();

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        $this->actingAs($guest)
            ->post('/views', ['name' => 'Mes tickets', 'priority' => 'high'])
            ->assertRedirect();

        $this->assertDatabaseHas('saved_views', ['name' => 'Mes tickets', 'created_by' => $guest->id]);
    }

    public function test_view_name_is_required_and_capped_at_255_characters(): void
    {
        [, $workspace, , $member] = $this->setupWorkspace();

        $this->actingAs($member)
            ->post('/views', ['priority' => 'high'])
            ->assertSessionHasErrors('name');

        $this->actingAs($member)
            ->post('/views', ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('name');
    }

    public function test_summary_lists_the_saved_filters_in_words(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $status = $workspace->statuses()->where('category', 'in_progress')->first();

        app()->setLocale('fr');

        $view = CreateSavedView::run($workspace, $member, [
            'name' => 'Urgences front',
            'filters' => [
                'project' => $project->id,
                'status' => $status->id,
                'priority' => 'urgent',
                'label' => [$bug->id, $front->id],
                'overdue' => '1',
                'due_after' => '2026-01-01',
                'due_before' => '2026-12-31',
                'q' => 'paiements',
            ],
            'sort' => 'priority',
            'dir' => 'desc',
        ]);

        $this->assertSame(
            'Projet : Site vitrine · Statut : In Progress · Priorité : urgente · Labels : bug, front · En retard · Échéance entre le 2026-01-01 et le 2026-12-31 · Recherche : « paiements » · Tri : priorité (décroissant)',
            $view->summary,
        );
    }

    public function test_view_without_filters_has_no_summary(): void
    {
        [, $workspace, , $member] = $this->setupWorkspace();

        $view = CreateSavedView::run($workspace, $member, ['name' => 'Tout', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->assertNull($view->summary);
        $this->assertNull($view->filters);
    }

    public function test_issues_page_exposes_saved_views_in_alphabetical_order(): void
    {
        [, $workspace, , $member] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $front = CreateLabel::run($workspace, ['name' => 'front']);

        CreateSavedView::run($workspace, $member, [
            'name' => 'Zeta',
            'filters' => ['priority' => 'high'],
            'sort' => null,
            'dir' => null,
        ]);
        CreateSavedView::run($workspace, $member, [
            'name' => 'Alpha',
            'filters' => ['label' => [$bug->id, $front->id], 'q' => 'paiements'],
            'sort' => 'priority',
            'dir' => 'desc',
        ]);

        $this->actingAs($member)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('savedViews', 2)
                ->where('savedViews.0.name', 'Alpha')
                ->where('savedViews.0.createdBy', $member->id)
                ->where('savedViews.0.summary', fn ($summary) => is_string($summary) && str_contains($summary, 'bug'))
                ->where('savedViews.0.filters', ['label' => [$bug->id, $front->id], 'q' => 'paiements'])
                ->where('savedViews.0.sort', 'priority')
                ->where('savedViews.0.dir', 'desc')
                ->where('savedViews.1.name', 'Zeta')
                ->where('savedViews.1.filters', ['priority' => 'high'])
                ->where('savedViews.1.sort', null)
                ->where('savedViews.1.dir', null));
    }

    public function test_guest_sees_saved_views_in_the_selector_with_scoped_results(): void
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $inScope = CreateProject::run($workspace, $owner, ['name' => 'Alpha']);
        $outOfScope = CreateProject::run($workspace, $owner, ['name' => 'Beta']);

        CreateIssue::run($workspace, ['project_id' => $inScope->id, 'title' => 'Dans le perimetre']);
        CreateIssue::run($workspace, ['project_id' => $outOfScope->id, 'title' => 'Hors perimetre']);

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

        // A workspace-wide view created by the owner: the definition is not
        // filtered by who looks at it — the results are.
        CreateSavedView::run($workspace, $owner, [
            'name' => 'Priorités hautes',
            'filters' => ['priority' => 'high'],
            'sort' => null,
            'dir' => null,
        ]);

        $this->actingAs($guest)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('savedViews', 1)
                ->where('savedViews.0.name', 'Priorités hautes')
                // Server-side scoping: only the in-perimeter issue is listed.
                ->has('issues', 1)
                ->where('totalUnfiltered', 1));
    }

    public function test_issues_page_source_carries_the_saved_views_selector(): void
    {
        // The issues page is client-rendered: the selector hooks are
        // asserted on the page and component sources, per the export
        // dialog convention.
        $page = file_get_contents(resource_path('js/Pages/Issues/Index.tsx'));
        $picker = file_get_contents(resource_path('js/Pages/Issues/Index/components/saved-views-picker.tsx'));

        // The page wires the picker into the filter bar and feeds it the prop.
        $this->assertStringContainsString('SavedViewsPicker', $page);
        $this->assertStringContainsString('savedViews', $page);

        // Applying a view navigates to the issues list; saving posts the
        // current filters to the existing creation endpoint.
        $this->assertStringContainsString("route('issues.index')", $picker);
        $this->assertStringContainsString("route('views.store')", $picker);
        $this->assertStringContainsString('data-test="saved-views-picker"', $picker);
        $this->assertStringContainsString('data-test="save-view-button"', $picker);
        $this->assertStringContainsString('data-test="save-view-name"', $picker);
        $this->assertStringContainsString('data-test="saved-views-empty"', $picker);

        // Managing a view: rename (owner only) and delete (owner or
        // creator), behind a per-view command shown only to those who may
        // act, with an explicit destructive confirmation.
        $this->assertStringContainsString("route('views.update'", $picker);
        $this->assertStringContainsString("route('views.destroy'", $picker);
        $this->assertStringContainsString("role === 'owner'", $picker);
        $this->assertStringContainsString('data-test="saved-view-manage"', $picker);
        $this->assertStringContainsString('data-test="saved-view-rename"', $picker);
        $this->assertStringContainsString('data-test="saved-view-delete"', $picker);
        $this->assertStringContainsString('data-test="rename-view-name"', $picker);
        $this->assertStringContainsString('data-test="confirm-delete-view"', $picker);
    }

    public function test_owner_can_rename_any_view(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $view = CreateSavedView::run($workspace, $member, ['name' => 'Urgences', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($owner)
            ->patch("/views/{$view->id}", ['name' => 'Urgences du vendredi'])
            ->assertRedirect(route('issues.index'));

        $view->refresh();
        $this->assertSame('Urgences du vendredi', $view->name);

        // Renaming to its own name is not a duplicate.
        $this->actingAs($owner)
            ->patch("/views/{$view->id}", ['name' => 'Urgences du vendredi'])
            ->assertRedirect(route('issues.index'));
    }

    public function test_rename_keeps_name_unique_per_workspace(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $first = CreateSavedView::run($workspace, $member, ['name' => 'Première', 'filters' => [], 'sort' => null, 'dir' => null]);
        CreateSavedView::run($workspace, $member, ['name' => 'Deuxième', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($owner)
            ->patch("/views/{$first->id}", ['name' => 'Deuxième'])
            ->assertSessionHasErrors('name');

        // The same name is fine in another workspace.
        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $theirView = CreateSavedView::run($otherWorkspace, $other, ['name' => 'Leur vue', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($other)
            ->patch("/views/{$theirView->id}", ['name' => 'Première'])
            ->assertRedirect(route('issues.index'));
    }

    public function test_non_owner_cannot_rename_a_view(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $view = CreateSavedView::run($workspace, $owner, ['name' => 'Urgences', 'filters' => [], 'sort' => null, 'dir' => null]);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        $this->actingAs($member)
            ->patch("/views/{$view->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($guest)
            ->patch("/views/{$view->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $view->refresh();
        $this->assertSame('Urgences', $view->name);
    }

    public function test_owner_can_delete_any_view(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $view = CreateSavedView::run($workspace, $member, ['name' => 'Urgences', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($owner)
            ->delete("/views/{$view->id}")
            ->assertRedirect(route('issues.index'));

        $this->assertDatabaseMissing('saved_views', ['id' => $view->id]);
    }

    public function test_creator_can_delete_own_view(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $ownView = CreateSavedView::run($workspace, $member, ['name' => 'Ma vue', 'filters' => [], 'sort' => null, 'dir' => null]);

        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);
        $guestView = CreateSavedView::run($workspace, $guest, ['name' => 'Vue invitée', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($member)
            ->delete("/views/{$ownView->id}")
            ->assertRedirect(route('issues.index'));

        $this->actingAs($guest)
            ->delete("/views/{$guestView->id}")
            ->assertRedirect(route('issues.index'));

        $this->assertDatabaseMissing('saved_views', ['id' => $ownView->id]);
        $this->assertDatabaseMissing('saved_views', ['id' => $guestView->id]);
    }

    public function test_member_cannot_delete_another_members_view(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $ownersView = CreateSavedView::run($workspace, $owner, ['name' => 'Vue du Owner', 'filters' => [], 'sort' => null, 'dir' => null]);

        $this->actingAs($member)
            ->delete("/views/{$ownersView->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('saved_views', ['id' => $ownersView->id]);
    }

    public function test_missing_or_cross_workspace_view_actions_return_not_found(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->patch('/views/01m63jp9zm4k27226zzcs7xntq', ['name' => 'Nulle part'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete('/views/01m63jp9zm4k27226zzcs7xntq')
            ->assertNotFound();

        // A view of another workspace does not exist here: same answer.
        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $theirView = CreateSavedView::run($otherWorkspace, $other, ['name' => 'Leur vue', 'filters' => [], 'sort' => null, 'dir' => null]);

        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->patch("/views/{$theirView->id}", ['name' => 'Vol'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete("/views/{$theirView->id}")
            ->assertNotFound();
    }
}
