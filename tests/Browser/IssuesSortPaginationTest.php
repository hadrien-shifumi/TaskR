<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Models\Issue;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;

const SORTED_ISSUE_ROWS = '[data-test^="issue-row-"]';

/**
 * Seeds the final-gate scenario of the filters/sort/pagination chantier:
 * one project, two labels, four issues covering the precise status, the
 * multi-label and the due-date range filters.
 *
 *  - webhook  In Progress · front, back · due 2026-03-10
 *  - tokens   Done        · front       · due 2026-03-20
 *  - crash    In Progress · back        · due 2026-04-10
 *  - legacy   Done        · none        · no due date
 *
 * @return array{owner: User, workspace: Workspace, issues: array<string, Issue>, labels: array<string, string>}
 */
function seedIssuesSortPaginationWorkspace(): array
{
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $project = CreateProject::run($workspace, $owner, ['name' => 'Payments']);

    $front = CreateLabel::run($workspace, ['name' => 'front']);
    $back = CreateLabel::run($workspace, ['name' => 'back']);

    $statuses = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->get()
        ->keyBy(fn (Status $status) => $status->category->value);

    $issue = fn (string $title, string $category, array $extra = []) => CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => $title,
        'status_id' => $statuses[$category]->id,
        ...$extra,
    ]);

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'labels' => ['front' => $front->id, 'back' => $back->id],
        'issues' => [
            'webhook' => $issue('Webhook retry with backoff', 'in_progress', [
                'label_ids' => [$front->id, $back->id],
                'due_date' => '2026-03-10',
            ]),
            'tokens' => $issue('Design color tokens', 'done', [
                'label_ids' => [$front->id],
                'due_date' => '2026-03-20',
            ]),
            'crash' => $issue('Crash on launch with iOS 17.4', 'in_progress', [
                'label_ids' => [$back->id],
                'due_date' => '2026-04-10',
            ]),
            'legacy' => $issue('Rewrite the legacy module', 'done'),
        ],
    ];
}

/**
 * Seeds 51 issues created one minute apart (Ticket 01 … Ticket 51), the
 * first 50 labelled `front` and the last one labelled `back`, so the
 * pagination scenario can filter down to a two-page list.
 *
 * @return array{owner: User, workspace: Workspace, issues: array<string, Issue>, labels: array<string, string>}
 */
function seedPaginatedIssuesWorkspace(): array
{
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    $project = CreateProject::run($workspace, $owner, ['name' => 'Payments']);

    $front = CreateLabel::run($workspace, ['name' => 'front']);
    $back = CreateLabel::run($workspace, ['name' => 'back']);

    $issues = [];

    for ($i = 1; $i <= 51; $i++) {
        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => sprintf('Ticket %02d', $i),
            'label_ids' => [$i === 51 ? $back->id : $front->id],
        ]);
        $issue->forceFill(['created_at' => sprintf('2026-01-01 10:%02d:00', $i)])->save();

        $issues[sprintf('ticket-%02d', $i)] = $issue;
    }

    return [
        'owner' => $owner,
        'workspace' => $workspace,
        'labels' => ['front' => $front->id, 'back' => $back->id],
        'issues' => $issues,
    ];
}

/**
 * Picks a label in the multi-select menu. The Base UI trigger only focuses
 * on the first click, the second opens the menu; the option is clicked
 * through the DOM because the menu item swallows pointer clicks. The menu
 * closes itself when the pick triggers an Inertia visit, so the trigger is
 * clicked until it reports an open menu.
 */
function pickLabelFilter($page, string $label): void
{
    $page->script(<<<'JS'
        (async () => {
            const trigger = document.querySelector('[data-test="filter-labels"]');
            const deadline = Date.now() + 5000;

            while (Date.now() < deadline) {
                if (trigger.getAttribute('aria-expanded') === 'true') {
                    return true;
                }

                trigger.click();
                await new Promise((resolve) => setTimeout(resolve, 100));
            }

            return false;
        })()
        JS);

    $page->script(<<<JS
        (async () => {
            const deadline = Date.now() + 5000;

            while (Date.now() < deadline) {
                const option = Array.from(document.querySelectorAll('[data-test="filter-labels-option"]'))
                    .find((element) => element.textContent.includes('{$label}'));

                if (option !== undefined) {
                    option.click();

                    return true;
                }

                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return false;
        })()
        JS);
}

/**
 * Waits until the Inertia visit triggered by a filter change has landed:
 * the URL must carry at least `$count` `label[]` values.
 */
function waitForLabelCount($page, int $count): void
{
    $page->script(<<<JS
        (async () => {
            const deadline = Date.now() + 5000;

            while (Date.now() < deadline) {
                const labels = (location.search.match(/label(?:\[\d+\]|%5B\d+%5D)=/g) ?? []).length;

                if (labels >= {$count}) {
                    return true;
                }

                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return false;
        })()
        JS);
}

/** Reads the `label[]` URL values as a sorted list, order-agnostic. */
function currentLabelParam($page): array
{
    parse_str((string) parse_url($page->url(), PHP_URL_QUERY), $query);

    $labels = (array) ($query['label'] ?? $query['label[]'] ?? []);
    sort($labels);

    return $labels;
}

it('keeps precise status, multi-label and due-date filters in a shareable URL', function () {
    ['owner' => $owner, 'workspace' => $workspace, 'labels' => $labels] = seedIssuesSortPaginationWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->assertCount(SORTED_ISSUE_ROWS, 4);
    $page->assertSeeIn('@filter-count', '4 / 4');

    // (1) Precise status: the "In Progress" workspace status.
    $inProgress = Status::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->where('category', 'in_progress')
        ->firstOrFail();

    $page->click('@filter-select-status');
    $page->click('[role="option"]:has-text("In Progress")');
    $page->assertQueryStringHas('status', $inProgress->id);
    $page->assertCount(SORTED_ISSUE_ROWS, 2);
    $page->assertSeeIn('@filter-count', '2 / 4');

    // (2) Multi-label: picking a first label narrows the list, a second one
    // widens the match (OR), then the precise status AND the labels combine.
    pickLabelFilter($page, 'front');
    waitForLabelCount($page, 1);
    expect(currentLabelParam($page))->toBe([$labels['front']]);
    $page->assertCount(SORTED_ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');

    // The Inertia visit closed the menu: reopen it before the second pick.
    pickLabelFilter($page, 'back');
    $page->script(<<<JS
        (async () => {
            const deadline = Date.now() + 5000;
            while (Date.now() < deadline) {
                const labels = (location.search.match(/label(?:\[\d+\]|%5B\d+%5D)=/g) ?? []).length;
                if (labels === 2) {
                    return true;
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return false;
        })()
        JS);
    expect(currentLabelParam($page))->toBe(collect([$labels['front'], $labels['back']])->sort()->values()->all());
    $page->assertCount(SORTED_ISSUE_ROWS, 2);
    $page->assertSeeIn('@filter-count', '2 / 4');

    // Close the dropdown before touching the date inputs.
    $page->script('document.body.click()');

    // (3) Due-date range: only the webhook issue is due in March 2026.
    $page->fill('@filter-due-after', '2026-03-01');
    $page->assertQueryStringHas('due_after', '2026-03-01');
    $page->fill('@filter-due-before', '2026-03-31');
    $page->assertQueryStringHas('due_before', '2026-03-31');
    $page->assertCount(SORTED_ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');
    $page->assertSeeIn('@filter-count', '1 / 4');

    $sharedUrl = $page->url();

    // The URL is shareable: a fresh visit restores the same results, the
    // same controls and the same counter.
    $fresh = visit($sharedUrl);

    $fresh->assertCount(SORTED_ISSUE_ROWS, 1);
    $fresh->assertSee('Webhook retry with backoff');
    $fresh->assertDontSee('Design color tokens');
    $fresh->assertSeeIn('@filter-count', '1 / 4');
    $fresh->assertSeeIn('@filter-select-status', 'In Progress');
    $fresh->assertSeeIn('@filter-labels', '2');
    $fresh->assertValue('@filter-due-after', '2026-03-01');
    $fresh->assertValue('@filter-due-before', '2026-03-31');

    $fresh->assertNoSmoke();
});

it('toggles the column sort between ascending and descending', function () {
    ['owner' => $owner] = seedIssuesSortPaginationWorkspace();
    $this->actingAs($owner);

    $page = visit('/issues');

    $rowSelector = '[data-test^="issue-row-"]';
    $dueDateHead = 'th:has([data-test="sort-due-date"])';

    // Default order: created desc, the due-date column is not sorted yet.
    $page->assertAttributeContains($dueDateHead, 'aria-sort', 'none');

    // First click: ascending by due date, the nearest deadline first.
    $page->click('@sort-due-date');
    $page->assertQueryStringHas('sort', 'due_date');
    $page->assertQueryStringHas('dir', 'asc');
    $page->assertAttributeContains($dueDateHead, 'aria-sort', 'ascending');
    $page->assertSeeIn("{$rowSelector}:first-child", 'Webhook retry with backoff');

    // Second click: descending, the farthest deadline first (nulls last).
    $page->click('@sort-due-date');
    $page->assertQueryStringHas('sort', 'due_date');
    $page->assertQueryStringHas('dir', 'desc');
    $page->assertAttributeContains($dueDateHead, 'aria-sort', 'descending');
    $page->assertSeeIn("{$rowSelector}:first-child", 'Crash on launch with iOS 17.4');

    // The sort is shareable too.
    $fresh = visit('/issues?sort=due_date&dir=asc');
    $fresh->assertSeeIn("{$rowSelector}:first-child", 'Webhook retry with backoff');
    $fresh->assertAttributeContains($dueDateHead, 'aria-sort', 'ascending');

    $fresh->assertNoSmoke();
});

it('paginates the filtered list and keeps the filters and sort in the URL', function () {
    ['owner' => $owner, 'labels' => $labels] = seedPaginatedIssuesWorkspace();
    $this->actingAs($owner);

    // 51 issues, 50 per page: the pagination bar only shows beyond page 1.
    $page = visit('/issues');

    $page->assertCount(SORTED_ISSUE_ROWS, 50);
    $page->assertSeeIn('@issues-pagination-counter', 'Page 1 of 2 · total 51');
    $page->assertSeeIn('@filter-count', '50 / 51');

    // A filter narrowing the list to two pages of its own. The labelled
    // count ("visible / total") only reflects the filter once the client
    // re-renders, so the pagination counter is the deterministic anchor.
    pickLabelFilter($page, 'front');
    waitForLabelCount($page, 1);
    $page->script('document.body.click()');
    expect(currentLabelParam($page))->toBe([$labels['front']]);

    // A sort on top of it.
    $page->script('document.querySelector(\'[data-test="sort-due-date"]\').click()');
    $page->assertQueryStringHas('sort', 'due_date');
    $page->assertQueryStringHas('dir', 'asc');

    // The 50 labelled tickets fit on one page: the pagination bar
    // disappears and the counter reflects the narrowed total.
    $page->assertMissing('@issues-pagination');
    $page->assertCount(SORTED_ISSUE_ROWS, 50);

    // The page-2 URL of the filtered list is shareable: the next slice,
    // with the filter and the sort carried in the URL.
    $paged = visit('/issues?label[]='.$labels['front'].'&sort=due_date&dir=asc&page=2');
    $paged->assertQueryStringHas('page', '2');
    // A page beyond the last one clamps to the last page (1 here): the
    // pagination bar is hidden and the whole filtered list is shown.
    $paged->assertMissing('@issues-pagination');
    $paged->assertCount(SORTED_ISSUE_ROWS, 50);
    $paged->assertQueryStringHas('sort', 'due_date');
    $paged->assertQueryStringHas('dir', 'asc');

    // An out-of-range page clamps to the last page, never 404s.
    $clamped = visit('/issues?page=99');
    $clamped->assertSeeIn('@issues-pagination-counter', 'Page 2 of 2 · total 51');

    $clamped->assertNoSmoke();
});

it('finds an issue by its copied identifier or a seven-character prefix', function () {
    ['owner' => $owner, 'issues' => $issues] = seedIssuesSortPaginationWorkspace();
    $this->actingAs($owner);

    $webhook = $issues['webhook'];

    $prefix = substr($webhook->id, 0, 7);

    // Copy the full identifier from the detail page, as a user would.
    $detail = visit('/issues/'.$webhook->id);
    $detail->script('window.__copied = null');
    $detail->script('navigator.clipboard.writeText = (text) => { window.__copied = text; return Promise.resolve(); }');
    $detail->click('@copy-identifier');

    expect($detail->script('window.__copied'))->toBe($webhook->id);

    // Paste it into the tickets search: the issue shows up.
    $page = visit('/issues');
    $page->fill('@filter-search', $webhook->id);
    $page->assertQueryStringHas('q', $webhook->id);
    $page->assertCount(SORTED_ISSUE_ROWS, 1);
    $page->assertSee('Webhook retry with backoff');
    $page->assertSeeIn('@filter-count', '1 / 4');

    // A seven-character prefix resolves to the same ticket. A fresh visit
    // avoids the debounce race of a second fill on the same input. The
    // shared ULID timestamp prefix matches the whole seeded batch, so the
    // scenario pins one ticket whose seven characters are unique.
    $unique = $issues['legacy'];
    $unique->forceFill(['id' => 'Z7K3M9Q4R2T6V8W1X5Y0B3C4D5'])->save();
    $unique->refresh();

    $prefixPage = visit('/issues');
    $prefixPage->fill('@filter-search', substr($unique->id, 0, 7));
    $prefixPage->assertQueryStringHas('q', substr($unique->id, 0, 7));
    $prefixPage->assertCount(SORTED_ISSUE_ROWS, 1);
    $prefixPage->assertSee('Rewrite the legacy module');

    // No match: the existing filtered empty state.
    $emptyPage = visit('/issues');
    $emptyPage->fill('@filter-search', 'no-such-issue');
    $emptyPage->assertQueryStringHas('q', 'no-such-issue');
    $emptyPage->assertVisible('@issues-empty');
    $emptyPage->assertCount(SORTED_ISSUE_ROWS, 0);

    $emptyPage->assertNoSmoke();
});
