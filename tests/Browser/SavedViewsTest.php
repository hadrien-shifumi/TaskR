<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Actions\SavedViews\CreateSavedView;
use App\Models\Membership;
use App\Models\SavedView;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;

/**
 * Seeds the saved-views scenario: one project, one urgent issue and one
 * quiet issue, plus a regular member.
 *
 * @return array{0: User, 1: Workspace, 2: Project, 3: User}
 */
function seedSavedViewsBrowserWorkspace(): array
{
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $project = CreateProject::run($workspace, $owner, ['name' => 'Payments']);

    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);

    CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Webhook retry with backoff', 'priority' => 'urgent']);
    CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Design color tokens']);

    return [$owner, $workspace, $project, $member];
}

const SAVED_VIEW_ROWS = '[data-test^="issue-row-"]';

it('saves a filtered view, applies it from the selector, then renames and deletes it as owner', function () {
    [$owner, $workspace, $project] = seedSavedViewsBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');
    $page->assertCount(SAVED_VIEW_ROWS, 2);

    // Filter by urgent priority and sort by the priority column: the URL
    // carries the configuration the view will capture.
    $page->click('@filter-select-priority');
    $page->click('[role="option"]:has-text("urgent")');
    $page->assertQueryStringHas('priority', 'urgent');
    $page->assertCount(SAVED_VIEW_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');
    $page->assertDontSee('Design color tokens');

    $page->click('@sort-priority');
    $page->assertQueryStringHas('sort', 'priority');
    $page->assertQueryStringHas('dir', 'asc');

    // Save the current filters and sort as a shared view.
    $page->click('@save-view-button');
    $page->fill('@save-view-name', 'Urgent payments');
    $page->click('@form-dialog-submit');
    $page->assertSeeIn('@flash-banner', 'View "Urgent payments" saved.');

    app(WorkspaceContext::class)->set($workspace);
    expect(SavedView::withoutGlobalScope(WorkspaceScope::class)->count())->toBe(1);

    // Break the filters by hand: the whole list is back.
    $page->click('@filter-select-priority');
    $page->click('[role="option"]:has-text("All priorities")');
    $page->assertQueryStringMissing('priority');
    $page->assertCount(SAVED_VIEW_ROWS, 2);

    // One click on the saved view restores its filters and sort.
    $page->click('@saved-views-picker');
    $page->click('[data-test="saved-view-option"]:has-text("Urgent payments")');
    $page->assertQueryStringHas('priority', 'urgent');
    $page->assertQueryStringHas('sort', 'priority');
    $page->assertQueryStringHas('dir', 'asc');
    $page->assertCount(SAVED_VIEW_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');
    $page->assertDontSee('Design color tokens');

    // The owner renames any view.
    $page->click('@saved-views-picker');
    $page->click('@saved-view-manage');
    $page->click('@saved-view-rename');
    $page->fill('@rename-view-name', 'Urgent payments v2');
    $page->click('@form-dialog-submit');
    $page->assertSeeIn('@flash-banner', 'View "Urgent payments v2" renamed.');

    // The owner deletes any view, behind the destructive confirmation.
    $page->click('@saved-views-picker');
    $page->click('[data-test="saved-view-option"]:has-text("Urgent payments v2")');
    $page->assertQueryStringHas('priority', 'urgent');

    $page->click('@saved-views-picker');
    $page->click('@saved-view-manage');
    $page->click('@saved-view-delete');
    $page->click('@confirm-delete-view');
    $page->assertSeeIn('@flash-banner', 'View "Urgent payments v2" deleted.');

    // The selector is back to its explained empty state, the database too.
    $page->click('@saved-views-picker');
    $page->assertSee('No saved views yet');

    app(WorkspaceContext::class)->set($workspace);
    expect(SavedView::withoutGlobalScope(WorkspaceScope::class)->count())->toBe(0);

    $page->assertNoSmoke();
});

it('lets a member delete their own view but hides rename and foreign management', function () {
    [$owner, $workspace, , $member] = seedSavedViewsBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    CreateSavedView::run($workspace, $owner, ['name' => 'Owner view', 'filters' => ['priority' => 'urgent'], 'sort' => null, 'dir' => null]);
    CreateSavedView::run($workspace, $member, ['name' => 'Member view', 'filters' => ['priority' => 'low'], 'sort' => null, 'dir' => null]);

    $this->actingAs($member);

    $page = visit('/issues');

    // The selector lists every shared view, alphabetical and flat.
    $page->click('@saved-views-picker');
    $page->assertCount('@saved-view-option', 2);
    $page->assertSee('Member view');
    $page->assertSee('Owner view');

    // The manage command appears on the member's own view only.
    $page->assertCount('@saved-view-manage', 1);

    // Rename is owner-only: the member's submenu offers delete alone.
    $page->click('@saved-view-manage');
    $page->assertMissing('@saved-view-rename');
    $page->assertPresent('@saved-view-delete');

    // The member deletes their own view, with confirmation.
    $page->click('@saved-view-delete');
    $page->click('@confirm-delete-view');
    $page->assertSeeIn('@flash-banner', 'View "Member view" deleted.');

    // The owner's view survives, and no manage command remains.
    $page->click('@saved-views-picker');
    $page->assertCount('@saved-view-option', 1);
    $page->assertSee('Owner view');
    $page->assertMissing('@saved-view-manage');

    app(WorkspaceContext::class)->set($workspace);
    $remaining = SavedView::withoutGlobalScope(WorkspaceScope::class)->pluck('name')->all();
    expect($remaining)->toBe(['Owner view']);

    $page->assertNoSmoke();
});
