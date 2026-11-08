<?php

use App\Actions\Comments\AddComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\MoveIssue;
use App\Support\WorkspaceContext;

/**
 * The comments and activity probes below measure the clamped body through
 * scrollHeight vs clientHeight: a line-clamped element keeps its overflowing
 * content in the layout but visually hidden, which is exactly the truncation
 * under test.
 */
it('collapses the comments and activity sections by default and toggles them from their header', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $backlog = $workspace->statuses()->where('name', 'Backlog')->firstOrFail();
    $inProgress = $workspace->statuses()->where('name', 'In Progress')->firstOrFail();
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Fiche compacte',
        'status_id' => $backlog->id,
    ], $owner);
    MoveIssue::run($issue, $inProgress->id, $owner);
    AddComment::run($issue, $owner, 'Premier commentaire');
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);

    // Collapsed by default: the headers carry the title and the counter…
    $page->assertSeeIn('[data-test="comments-accordion-trigger"]', 'Comments (1)')
        ->assertSeeIn('[data-test="activity-accordion-trigger"]', 'Activity')
        // …while the bodies stay out of the DOM.
        ->assertNotPresent('[data-test="comments-panel"]')
        ->assertNotPresent('[data-test="activity-panel"]')
        ->assertDontSee('Premier commentaire')
        ->assertDontSee('Issue created');

    // Opening the comments accordion reveals the thread and the comment box.
    $page->click('[data-test="comments-accordion-trigger"]')
        ->assertPresent('[data-test="comments-panel"]')
        ->assertSeeIn('[data-test="comments-panel"]', 'Premier commentaire');

    // A second click folds it back.
    $page->click('[data-test="comments-accordion-trigger"]')
        ->assertNotPresent('[data-test="comments-panel"]');

    // Same toggle for the activity timeline.
    $page->click('[data-test="activity-accordion-trigger"]')
        ->assertPresent('[data-test="activity-panel"]')
        ->assertSeeIn('[data-test="activity-panel"]', 'Issue created');

    $page->click('[data-test="activity-accordion-trigger"]')
        ->assertNotPresent('[data-test="activity-panel"]')
        ->assertNoSmoke();
});

it('truncates a long comment with a show more control', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Commentaire fleuve',
    ], $owner);

    // A body well beyond the clamp: opening lines visible, tail hidden.
    $tail = 'LA FIN DU COMMENTAIRE NE DOIT PAS ETRE VISIBLE AVANT LE DEPLIEMENT.';
    AddComment::run($issue, $owner, 'Debut du commentaire. '.str_repeat('Une phrase de remplissage pour gonfler le corps. ', 60).$tail);
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);
    $page->click('[data-test="comments-accordion-trigger"]');

    // The deferred comments land after hydration: poll for the clamp.
    $probe = ['found' => false];
    foreach (range(1, 30) as $_) {
        $probe = $page->script(<<<'JS'
            (() => {
                const el = document.querySelector('[data-test="comment-clamp"]');

                return el === null
                    ? { found: false }
                    : { found: true, clamped: el.scrollHeight > el.clientHeight + 1, clientHeight: el.clientHeight };
            })()
            JS);

        if (($probe['found'] ?? false) && ($probe['clamped'] ?? false)) {
            break;
        }

        usleep(100_000);
    }

    expect($probe['found'])->toBeTrue()
        ->and($probe['clamped'])->toBeTrue()
        ->and($probe['clientHeight'])->toBeLessThan(250);

    // The tail is laid out below the clamped box: out of sight until the
    // control unfolds the body (Playwright sees line-clamped text as
    // "visible" since its layout box exists, hence the geometric probe).
    $tailHidden = $page->script(<<<'JS'
        (() => {
            const clamp = document.querySelector('[data-test="comment-clamp"]');
            if (clamp === null) {
                return { found: false };
            }
            const walker = document.createTreeWalker(clamp, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                const text = node.textContent ?? '';
                const index = text.indexOf('LA FIN DU COMMENTAIRE');
                if (index !== -1) {
                    const range = document.createRange();
                    range.setStart(node, index);
                    range.setEnd(node, Math.min(index + 12, text.length));
                    const rect = range.getBoundingClientRect();
                    const box = clamp.getBoundingClientRect();
                    return {
                        found: true,
                        hidden: rect.top >= box.bottom - 1,
                        tailTop: rect.top,
                        boxBottom: box.bottom,
                        boxHeight: box.height,
                    };
                }
            }
            return { found: false };
        })()
        JS);

    expect($tailHidden['found'])->toBeTrue()
        ->and($tailHidden['hidden'])->toBeTrue();

    $page->assertPresent('[data-test="comment-expand"]')
        ->click('[data-test="comment-expand"]')
        ->assertSee($tail)
        ->assertPresent('[data-test="comment-collapse"]')
        ->assertNotPresent('[data-test="comment-expand"]');

    // …and folds back on "show less".
    $page->click('[data-test="comment-collapse"]');

    $probe = $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-test="comment-clamp"]');

            return el === null ? { found: false } : { found: true, clamped: el.scrollHeight > el.clientHeight + 1 };
        })()
        JS);

    expect($probe['found'])->toBeTrue()
        ->and($probe['clamped'])->toBeTrue();

    $page->assertPresent('[data-test="comment-expand"]')
        ->assertNoSmoke();
});

it('shows no clamp control on a short comment', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, [
        'project_id' => $project->id,
        'title' => 'Commentaire court',
    ], $owner);
    AddComment::run($issue, $owner, 'Bref et clair.');
    $this->actingAs($owner);

    $page = visit('/issues/'.$issue->id);
    $page->click('[data-test="comments-accordion-trigger"]')
        ->assertSeeIn('[data-test="comments-panel"]', 'Bref et clair.')
        ->assertNotPresent('[data-test="comment-expand"]')
        ->assertNotPresent('[data-test="comment-collapse"]')
        ->assertNoSmoke();
});
