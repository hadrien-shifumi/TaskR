<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Projects\CreateProject;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

/**
 * Seeds the reference filter scenario of the issues list (maquette
 * screens/issues.html): two projects and five issues covering every chip.
 *
 *  - webhook  Payments · In Progress · urgent · due yesterday  · owner
 *             → in progress, overdue, urgent, mine, Payments
 *  - tokens   Payments · Done        · none   · no due date    · member
 *  - crash    Payments · In Progress · low    · due tomorrow   · member
 *  - migrate  Mobile   · Backlog     · high   · due yesterday  · unassigned
 *  - legacy   Mobile   · Cancelled   · none   · no due date    · unassigned
 *
 * @return array{owner: User, workspace: Workspace, payments: Project, mobile: Project, member: User, issues: array<string, Issue>}
 */
function seedIssuesFiltersWorkspace(): array
{
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $payments = CreateProject::run($workspace, ['name' => 'Payments']);
    $mobile = CreateProject::run($workspace, ['name' => 'Mobile']);

    $member = User::factory()->create();
    Membership::create(['user_id' => $member->id, 'workspace_id' => $workspace->id, 'role' => 'member']);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    $issue = fn (string $title, string $projectId, string $category, array $extra = []) => CreateIssue::run($workspace, [
        'project_id' => $projectId,
        'title' => $title,
        'status_id' => $statuses[$category]->id,
        ...$extra,
    ]);

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'payments' => $payments,
        'mobile' => $mobile,
        'member' => $member,
        'issues' => [
            'webhook' => $issue('Webhook retry with backoff', $payments->id, 'in_progress', [
                'priority' => 'urgent',
                'due_date' => now()->subDay()->toDateString(),
                'assignees' => [$owner->id],
            ]),
            'tokens' => $issue('Design color tokens', $payments->id, 'done', [
                'assignees' => [$member->id],
            ]),
            'crash' => $issue('Crash on launch with iOS 17.4', $payments->id, 'in_progress', [
                'priority' => 'low',
                'due_date' => now()->addDay()->toDateString(),
                'assignees' => [$member->id],
            ]),
            'migrate' => $issue('Migrate API to eu-west-3', $mobile->id, 'backlog', [
                'priority' => 'high',
                'due_date' => now()->subDay()->toDateString(),
            ]),
            'legacy' => $issue('Rewrite the legacy module', $mobile->id, 'cancelled'),
        ],
    ];
}

const ISSUE_ROWS = '[data-test^="issue-row-"]';

it('filters the list with cumulative chips and a visible/total counter', function () {
    ['owner' => $owner] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->assertCount(ISSUE_ROWS, 5);
    $page->assertSeeIn('@filter-count', '5 / 5');

    // "In progress" keeps the two in-progress issues only.
    $page->click('@filter-chip-in-progress');
    $page->assertQueryStringHas('category', 'in_progress');
    $page->assertCount(ISSUE_ROWS, 2);
    $page->assertSee('Webhook retry with backoff');
    $page->assertSee('Crash on launch with iOS 17.4');
    $page->assertDontSee('Design color tokens');
    $page->assertSeeIn('@filter-count', '2 / 2');

    // Chips cumulate (AND): in progress AND overdue keeps the webhook issue.
    $page->click('@filter-chip-overdue');
    $page->assertQueryStringHas('category', 'in_progress');
    $page->assertQueryStringHas('overdue', '1');
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertDontSee('Crash on launch with iOS 17.4');
    $page->assertSeeIn('@filter-count', '1 / 1');

    // Toggling a chip off removes its URL param and widens the list again.
    $page->click('@filter-chip-in-progress');
    $page->assertQueryStringMissing('category');
    $page->assertQueryStringHas('overdue', '1');
    $page->assertCount(ISSUE_ROWS, 2);
    $page->assertSee('Migrate API to eu-west-3');

    $page->click('@filter-chip-overdue');
    $page->assertQueryStringMissing('overdue');
    $page->assertCount(ISSUE_ROWS, 5);

    $page->assertNoSmoke();
});

it('searches issues by title through the debounced query param', function () {
    ['owner' => $owner] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->fill('@filter-search', 'webhook');
    $page->assertQueryStringHas('q', 'webhook');
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');
    $page->assertDontSee('Design color tokens');

    // Clearing the search removes the param and restores the full list.
    $page->fill('@filter-search', '');
    $page->assertQueryStringMissing('q');
    $page->assertCount(ISSUE_ROWS, 5);

    $page->assertNoSmoke();
});

it('shows the filtered empty state when nothing matches', function () {
    ['owner' => $owner] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->fill('@filter-search', 'no-such-issue');
    $page->assertVisible('@issues-empty');
    $page->assertSee('No issues match these filters');
    $page->assertSee('Disable a filter chip or clear the search.');
    $page->assertCount(ISSUE_ROWS, 0);

    // The empty state also covers chip combinations matching nothing.
    $page->fill('@filter-search', '');
    $page->assertQueryStringMissing('q');
    $page->assertMissing('@issues-empty');

    $page->click('@filter-chip-done');
    $page->click('@filter-chip-overdue');
    $page->assertCount(ISSUE_ROWS, 0);
    $page->assertVisible('@issues-empty');
    $page->assertSee('No issues match these filters');

    $page->click('@filter-chip-done');
    $page->click('@filter-chip-overdue');
    $page->assertCount(ISSUE_ROWS, 5);

    $page->assertNoSmoke();
});

it('filters to the current user with the mine chip', function () {
    ['owner' => $owner] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click('@filter-chip-mine');
    $page->assertQueryStringHas('assignee', (string) $owner->id);
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');
    $page->assertDontSee('Design color tokens');

    $page->click('@filter-chip-mine');
    $page->assertQueryStringMissing('assignee');
    $page->assertCount(ISSUE_ROWS, 5);

    $page->assertNoSmoke();
});

it('filters by project with one chip per project', function () {
    ['owner' => $owner, 'payments' => $payments, 'mobile' => $mobile] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click("@filter-chip-project-{$payments->id}");
    $page->assertQueryStringHas('project', $payments->id);
    $page->assertCount(ISSUE_ROWS, 3);
    $page->assertDontSee('Migrate API to eu-west-3');

    // The project filter is single-valued: picking another project replaces it.
    $page->click("@filter-chip-project-{$mobile->id}");
    $page->assertQueryStringHas('project', $mobile->id);
    $page->assertCount(ISSUE_ROWS, 2);
    $page->assertSee('Migrate API to eu-west-3');
    $page->assertDontSee('Webhook retry with backoff');

    $page->click("@filter-chip-project-{$mobile->id}");
    $page->assertQueryStringMissing('project');
    $page->assertCount(ISSUE_ROWS, 5);

    $page->assertNoSmoke();
});

it('filters urgent and done issues with their chips', function () {
    ['owner' => $owner] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click('@filter-chip-urgent');
    $page->assertQueryStringHas('priority', 'urgent');
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');

    $page->click('@filter-chip-urgent');
    $page->assertQueryStringMissing('priority');

    $page->click('@filter-chip-done');
    $page->assertQueryStringHas('category', 'done');
    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertSee('Design color tokens');
    $page->assertDontSee('Webhook retry with backoff');

    $page->assertNoSmoke();
});

it('reflects the URL filters on the chips and the search field', function () {
    ['owner' => $owner] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    // Filters are shareable: a bookmarked URL restores chips and search.
    $page = visit('/issues?category=in_progress&overdue=1&q=webhook');

    $page->assertCount(ISSUE_ROWS, 1);
    $page->assertAriaAttribute('@filter-chip-in-progress', 'pressed', 'true');
    $page->assertAriaAttribute('@filter-chip-overdue', 'pressed', 'true');
    $page->assertAriaAttribute('@filter-chip-done', 'pressed', 'false');
    $page->assertAriaAttribute('@filter-chip-mine', 'pressed', 'false');
    $page->assertValue('@filter-search', 'webhook');

    $page->assertNoSmoke();
});

it('renders rows with short ULID, project, assignees and due date', function () {
    ['owner' => $owner, 'issues' => $issues] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $webhook = $issues['webhook'];
    $row = "[data-test=\"issue-row-{$webhook->id}\"]";

    // Title sub-line: short ULID · project name (maquette col-title .sub).
    $page->assertSeeIn($row, substr($webhook->id, 0, 7).' · Payments');

    // Assignees render as an avatar stack; unassigned issues say so.
    $page->assertPresent("{$row} span[title=\"{$owner->name}\"]");
    $page->assertSeeIn("[data-test=\"issue-row-{$issues['migrate']->id}\"]", 'Unassigned');

    // Overdue due dates use the destructive tone; missing dates render —.
    $page->assertAttributeContains("{$row} td:last-child", 'class', 'text-destructive');
    $page->assertSeeIn("[data-test=\"issue-row-{$issues['tokens']->id}\"] td:last-child", '—');

    // The opaque-identifier footnote of the maquette.
    $page->assertSee('Identifiers are opaque (ULIDs)');

    $page->assertNoSmoke();
});

it('strikes through cancelled issues without linking them', function () {
    ['owner' => $owner, 'issues' => $issues] = seedIssuesFiltersWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $legacy = $issues['legacy'];
    $webhook = $issues['webhook'];

    // Cancelled: muted, line-through, and the title is not a link.
    $page->assertSee('Rewrite the legacy module');
    $page->assertAttributeContains("@issue-title-{$legacy->id}", 'class', 'line-through');
    $page->assertNotPresent("[data-test=\"issue-row-{$legacy->id}\"] a");

    // Regular issues keep their title link and no strike-through.
    $page->assertPresent("[data-test=\"issue-row-{$webhook->id}\"] a");
    $page->assertAttributeDoesntContain("@issue-title-{$webhook->id}", 'class', 'line-through');

    $page->assertNoSmoke();
});
