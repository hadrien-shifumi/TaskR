<?php

use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\SetIssueParent;
use App\Support\WorkspaceContext;

it('opens an issue in the board modal and closes back onto the board', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Bouton retour absurde',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // Clicking a card opens the fiche in a large modal — the user never
    // leaves the board.
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Bouton retour absurde');
    expect($page->script('location.pathname'))->toContain("/projects/{$project->id}/board");
    expect($page->script('location.search'))->toContain("issue={$issue->id}");

    // Closing the modal keeps the user on the board, modal-free.
    $page->click('[data-test="issue-detail-dialog"] [data-slot="dialog-close"]');
    $page->assertNotPresent('[data-test="issue-detail-dialog"]');
    expect($page->script('location.pathname'))->toContain("/projects/{$project->id}/board");
    expect($page->script('location.search'))->not->toContain('issue=');

    $page->assertNoSmoke();
});

it('copies the issue identifier from the board modal', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Identifiant copiable',
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");

    // The modal header mirrors the fiche pagebar: short mono identifier
    // with a copy action.
    $page->assertSeeIn('[data-test="issue-detail-dialog"]', substr($issue->id, 0, 7));

    $page->script('window.__copied = null');
    $page->script('navigator.clipboard.writeText = (text) => { window.__copied = text; return Promise.resolve(); }');
    $page->click('[data-test="issue-detail-dialog"] [data-test="copy-identifier"]');

    $page->assertSee('Copied');
    expect($page->script('window.__copied'))->toBe($issue->id);

    $page->assertNoSmoke();
});

it('navigates between child and parent issues inside the modal', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $parent = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Ticket parent',
        'status_id' => $backlog->id,
    ]);
    $child = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Sous-ticket enfant',
        'status_id' => $backlog->id,
    ]);
    SetIssueParent::run($child, $parent->id);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    // Open the parent fiche in the modal.
    $page->click("[data-test=\"board-card-{$parent->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Ticket parent');

    // The child link routes INSIDE the modal: the board stays put and the
    // issue param swaps. The h1 only shows the child title once its fiche
    // rendered in place of the parent's.
    $page->click('[data-test="issue-detail-dialog"] a:has-text("Sous-ticket enfant")');
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Sous-ticket enfant');
    expect($page->script('location.pathname'))->toContain("/projects/{$project->id}/board");
    expect($page->script('location.search'))->toContain("issue={$child->id}");

    // The parent link of the sidebar routes back inside the modal too.
    $page->click('[data-test="issue-detail-dialog"] a:has-text("Ticket parent")');
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Ticket parent');
    expect($page->script('location.search'))->toContain("issue={$parent->id}");

    $page->assertNoSmoke();
});

it('keeps the issue id header pinned to the modal top while the body scrolls', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'En-tête fixe',
        'description' => str_repeat("Paragraphe assez long pour forcer le defilement de la fiche en modal.\n\n", 60),
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'En-tête fixe');
    // Let the open animation finish before measuring pinned positions.
    $page->wait(0.5);

    // Scroll the modal's single scrollable area to the bottom: the header
    // row (short id + copy action) and the close button must stay pinned
    // to the top of the panel instead of being carried away with the body.
    $probe = <<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="issue-detail-dialog"]');
            const scrollable = panel.querySelector('[data-test="issue-detail-scroll"]');
            if (!scrollable) {
                return { scrolled: false, pinned: null };
            }
            scrollable.scrollTop = scrollable.scrollHeight;
            const panelRect = panel.getBoundingClientRect();
            const pinned = (selector) => {
                const el = panel.querySelector(selector);
                if (!el) {
                    return false;
                }
                const rect = el.getBoundingClientRect();
                return rect.top >= panelRect.top - 1 && rect.bottom <= panelRect.top + 60;
            };
            return {
                scrolled: scrollable.scrollTop > 0,
                pinned: pinned('[data-test="issue-ulid"]')
                    && pinned('[data-test="copy-identifier"]')
                    && pinned('[data-slot="dialog-close"]'),
            };
        })()
        JS;

    $result = $page->script($probe);

    expect($result['scrolled'])->toBeTrue('the modal body must actually scroll for this probe to mean anything');
    expect($result['pinned'])->toBeTrue('the short id, the copy action and the close button must stay pinned to the top of the modal while its body scrolls');

    $page->assertNoSmoke();
});

it('scrolls the whole modal body in a single container so the sidebar is not pinned', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Colonne laterale entrainee',
        'description' => str_repeat("Paragraphe assez long pour forcer le defilement de la fiche en modal.\n\n", 60),
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");
    // A short viewport makes the sidebar taller than the modal's visible
    // scroll area — the exact condition where a pinned sidebar hides its
    // own bottom. Width stays above the `lg:` breakpoint so the sidebar
    // sits in its right-hand column.
    $page->resize(1280, 600);
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Colonne laterale entrainee');
    // Let the open animation finish: its zoom transform rescales visual
    // rects and would distort the movement measurements below.
    $page->wait(0.5);

    // One scrollable container for the whole body: no second scroll area,
    // and the sidebar travels with the content instead of staying pinned
    // (measured between two scroll offsets; its bottom must stay reachable
    // mid-scroll, which a pinned taller-than-view sidebar breaks).
    $probe = <<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="issue-detail-dialog"]');
            const scrollable = panel.querySelector('[data-test="issue-detail-scroll"]');
            const aside = panel.querySelector('aside');
            if (!scrollable || !aside) {
                return {
                    scrolled: false,
                    singleScrollable: null,
                    sidebarTallerThanView: null,
                    sidebarMoved: null,
                    sidebarReachable: null,
                };
            }
            const scrollables = [panel, ...panel.querySelectorAll('*')].filter((el) => {
                const style = getComputedStyle(el);
                return (style.overflowY === 'auto' || style.overflowY === 'scroll')
                    && el.scrollHeight > el.clientHeight;
            });
            scrollable.scrollTop = 200;
            const topAt200 = aside.getBoundingClientRect().top;
            const scrolledFrom = scrollable.scrollTop;
            scrollable.scrollTop = 800;
            const topAt800 = aside.getBoundingClientRect().top;
            const scrolledBy = scrollable.scrollTop - scrolledFrom;
            const movedBy = topAt200 - topAt800;
            const scrollRect = scrollable.getBoundingClientRect();
            const asideRect = aside.getBoundingClientRect();
            return {
                scrolled: scrollable.scrollTop > 0,
                singleScrollable: scrollables.length === 1 && scrollables[0] === scrollable,
                sidebarTallerThanView: aside.scrollHeight > scrollable.clientHeight,
                sidebarMoved: movedBy >= scrolledBy - 10,
                sidebarReachable: asideRect.bottom <= scrollRect.bottom + 1,
            };
        })()
        JS;

    $result = $page->script($probe);

    expect($result['scrolled'])->toBeTrue('the modal body must actually scroll for this probe to mean anything');
    expect($result['singleScrollable'])->toBeTrue('the whole modal body must scroll inside a single container');
    expect($result['sidebarTallerThanView'])->toBeTrue('the sidebar must overflow the visible area for this probe to mean anything');
    expect($result['sidebarMoved'])->toBeTrue('the sidebar must scroll along with the body instead of staying pinned');
    expect($result['sidebarReachable'])->toBeTrue('the bottom of the sidebar must be reachable while the body scrolls');

    $page->assertNoSmoke();
});

it('keeps the close button pinned to the modal top while the content scrolls', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Fiche interminable',
        'description' => str_repeat("Paragraphe assez long pour forcer le defilement de la fiche en modal.\n\n", 60),
        'status_id' => $backlog->id,
    ]);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");
    $page->click("[data-test=\"board-card-{$issue->id}\"] a");
    $page->assertSeeIn('[data-test="issue-detail-dialog"] h1', 'Fiche interminable');

    // Scroll the modal's actual scrollable area to the bottom, then measure
    // the close button against the panel's top edge: it must stay pinned
    // there instead of being carried away with the scrolling content.
    $probe = <<<'JS'
        (() => {
            const panel = document.querySelector('[data-test="issue-detail-dialog"]');
            const scrollable = [panel, ...panel.querySelectorAll('div')].find((el) => {
                const style = getComputedStyle(el);
                return (style.overflowY === 'auto' || style.overflowY === 'scroll')
                    && el.scrollHeight > el.clientHeight;
            });
            if (!scrollable) {
                return { scrolled: false, pinned: null };
            }
            scrollable.scrollTop = scrollable.scrollHeight;
            const panelRect = panel.getBoundingClientRect();
            const buttonRect = panel.querySelector('[data-slot="dialog-close"]').getBoundingClientRect();
            return {
                scrolled: scrollable.scrollTop > 0,
                pinned: buttonRect.top >= panelRect.top - 1 && buttonRect.bottom <= panelRect.top + 60,
            };
        })()
        JS;

    $result = $page->script($probe);

    expect($result['scrolled'])->toBeTrue('the modal content must actually scroll for this probe to mean anything');
    expect($result['pinned'])->toBeTrue('the close button must stay pinned to the top of the modal while its content scrolls');

    $page->assertNoSmoke();
});
