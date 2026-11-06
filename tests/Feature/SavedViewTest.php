<?php

namespace Tests\Feature;

use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\SavedViews\CreateSavedView;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Membership;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
