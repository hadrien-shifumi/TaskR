<?php

use App\Actions\Issues\CreateIssue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

const BOARD_CARDS = '[data-test^="board-card-"]:not([data-test*="timer"])';
const ISSUE_ROWS = '[data-test^="issue-row-"]';

/**
 * Seeds two backlog issues on the browser project, one of them returned
 * for search assertions.
 *
 * @return array{0: User, 1: Workspace, 2: Project, 3: Issue}
 */
function seedProjectIssuesSearchWorkspace(): array
{
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $webhook = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Webhook retry',
        'status_id' => $backlog->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Design tokens',
        'status_id' => $backlog->id,
    ]);

    return [$owner, $workspace, $project, $webhook];
}

it('filters the project board by title through the debounced search', function () {
    [$owner,,, $webhook] = seedProjectIssuesSearchWorkspace();
    $this->actingAs($owner);

    $page = visit('/projects/'.($webhook->project_id).'/board');

    $page->assertVisible('@filter-search');
    $page->assertSeeIn('@filter-count', '2 / 2');

    $page->fill('@filter-search', 'webhook');
    // The counter is the last piece to land after the debounced reload.
    $page->waitForText('1 / 2');
    $page->assertQueryStringHas('q', 'webhook');
    $page->assertCount(BOARD_CARDS, 1);
    $page->assertVisible("[data-test=\"board-card-{$webhook->id}\"]");
    $page->assertSeeIn('@filter-count', '1 / 2');

    // Clearing the search removes the param and restores every card.
    $page->fill('@filter-search', '');
    $page->waitForText('2 / 2');
    $page->assertQueryStringMissing('q');
    $page->assertCount(BOARD_CARDS, 2);

    $page->assertNoSmoke();
});

it('filters the project board by a pasted identifier', function () {
    [$owner, $workspace, $project, $webhook] = seedProjectIssuesSearchWorkspace();
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // The full identifier (the "copy identifier" value) matches its ticket.
    $page->fill('@filter-search', $webhook->id);
    $page->waitForText('1 / 2');
    $page->assertQueryStringHas('q', $webhook->id);
    $page->assertCount(BOARD_CARDS, 1);
    $page->assertVisible("[data-test=\"board-card-{$webhook->id}\"]");

    $page->assertNoSmoke();
});

it('searches the project tickets list from the view switcher', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Webhook retry',
        'status_id' => $backlog->id,
    ]);
    CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Design tokens',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    // The new "Tickets" pill of the project switcher leads to the list.
    $page = visit("/projects/{$project->id}/board");
    $page->click('nav[aria-label="Project views"] a:has-text("Issues")');

    $page->assertCount(ISSUE_ROWS, 2);
    $page->assertSeeIn('@filter-count', '2 / 2');

    $page->fill('@filter-search', 'webhook');
    $page->assertQueryStringHas('q', 'webhook');
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry');

    // Nothing matches: the filtered empty state shows.
    $page->fill('@filter-search', 'no-such-issue');
    $page->assertVisible('@issues-empty');

    $page->assertNoSmoke();
});

it('opens the project tickets list from a shared filtered URL', function () {
    [$owner, $workspace, $project, $webhook] = seedProjectIssuesSearchWorkspace();
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/issues?q=webhook");

    // The search field is pre-filled from the URL: the view is shareable.
    $page->assertValue('@filter-search', 'webhook');
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertSeeIn('@filter-count', '1 / 2');

    $page->assertNoSmoke();
});
