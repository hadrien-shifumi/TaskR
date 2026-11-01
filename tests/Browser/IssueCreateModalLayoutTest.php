<?php

use App\Support\WorkspaceContext;

/**
 * Rectangles of the create-issue dialog parts, measured in the browser:
 * the panel width against the viewport, and the writing column against the
 * properties column.
 */
const ISSUE_CREATE_MODAL_PROBE = <<<'JS'
    (() => {
        const panel = document.querySelector('[data-slot="dialog-content"]');
        const redaction = panel.querySelector('[data-test="issue-redaction"]');
        const properties = panel.querySelector('[data-test="issue-properties"]');
        const description = panel.querySelector('[data-test="issue-description"]');
        const propertiesRect = properties.getBoundingClientRect();
        const descriptionRect = description.getBoundingClientRect();
        return {
            viewportWidth: window.innerWidth,
            panelWidth: panel.getBoundingClientRect().width,
            propertiesLeft: propertiesRect.left,
            descriptionRight: descriptionRect.right,
            propertiesRight: propertiesRect.right,
            redactionTop: redaction.getBoundingClientRect().top,
            propertiesTop: propertiesRect.top,
        };
    })()
    JS;

it('opens the issues page create dialog wide, with the properties on the right', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click('button:has-text("New issue")');
    $page->assertPresent('[data-test="issue-properties"]');

    $layout = $page->script(ISSUE_CREATE_MODAL_PROBE);

    // The dialog takes a large share of the screen instead of a narrow column.
    expect($layout['panelWidth'])->toBeGreaterThanOrEqual($layout['viewportWidth'] * 0.7);

    // Status, priority and labels sit to the right of the writing column.
    expect($layout['propertiesLeft'])->toBeGreaterThan($layout['descriptionRight']);
    // Both columns start at the same height: side by side, not stacked.
    expect(abs($layout['propertiesTop'] - $layout['redactionTop']))->toBeLessThan(40);

    $page->assertNoSmoke();
});

it('pins the create dialog header and footer while only the writing side scrolls', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/issues');
    $page->click('button:has-text("New issue")');
    $page->assertPresent('[data-test="issue-properties"]');

    // Fill the description with a long text so the writing column overflows.
    $page->script(<<<'JS'
        (() => {
            const editor = document.querySelector('[data-test="issue-description"] .ProseMirror');
            editor.focus();
            const text = Array.from({ length: 80 }, (_, i) => `Paragraphe ${i} assez long pour forcer le defilement de la redaction.`).join('\n\n');
            document.execCommand('insertText', false, text);
        })()
    JS);

    // The writing side scrolls its own editor area once it overflows.
    $probe = <<<'JS'
        (() => {
            const panel = document.querySelector('[data-slot="dialog-content"]');
            const body = document.querySelector('[data-test="issue-description"] [data-slot="markdown-editor"] > div:last-child');
            const properties = panel.querySelector('[data-test="issue-properties"]');
            const propertiesTopBefore = properties.getBoundingClientRect().top;
            body.scrollTop = body.scrollHeight;
            const panelRect = panel.getBoundingClientRect();
            const titleRect = panel.querySelector('[data-slot="dialog-title"]').getBoundingClientRect();
            const submitRect = panel.querySelector('button[type="submit"]').getBoundingClientRect();
            return {
                scrolled: body.scrollTop > 0,
                titlePinned: titleRect.top >= panelRect.top - 1 && titleRect.bottom <= panelRect.top + 120,
                submitPinned: submitRect.bottom <= panelRect.bottom + 1 && submitRect.top >= panelRect.bottom - 120,
                propertiesStill: Math.abs(properties.getBoundingClientRect().top - propertiesTopBefore) < 1,
                propertiesScrollable: properties.scrollHeight > properties.clientHeight,
            };
        })()
        JS;

    $result = $page->script($probe);

    expect($result['scrolled'])->toBeTrue('the writing side must actually scroll for this probe to mean anything');
    expect($result['titlePinned'])->toBeTrue('the dialog title must stay pinned at the top');
    expect($result['submitPinned'])->toBeTrue('the create button must stay pinned at the bottom');
    expect($result['propertiesStill'])->toBeTrue('the properties column must not move with the redaction scroll');
    expect($result['propertiesScrollable'])->toBeFalse('the properties column must not scroll');

    $page->assertNoSmoke();
});

it('keeps the create dialog fields compact, without stretched rows', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click('button:has-text("New issue")');
    $page->assertPresent('[data-test="issue-description"]');

    // The description block must not be taller than its label, its editor and
    // the 8px gap between them: a stretched row would push the editor away
    // from the label and leave a dead band under it.
    $spacing = $page->script(<<<'JS'
        (() => {
            const block = document.querySelector('[data-test="issue-description"]');
            const label = block.querySelector('[data-slot="label"]');
            const editor = block.querySelector('[data-slot="markdown-editor"]');
            const labelRect = label.getBoundingClientRect();
            const editorRect = editor.getBoundingClientRect();
            return {
                blockHeight: block.getBoundingClientRect().height,
                contentHeight: editorRect.bottom - labelRect.top,
                labelToEditor: editorRect.top - labelRect.bottom,
                editorToBlockBottom: block.getBoundingClientRect().bottom - editorRect.bottom,
            };
        })()
        JS);

    expect(abs($spacing['blockHeight'] - $spacing['contentHeight']))->toBeLessThan(2);
    expect($spacing['labelToEditor'])->toBeLessThan(12);
    expect($spacing['editorToBlockBottom'])->toBeLessThan(2);

    // Properties stack tightly in the right column: no stretched rows either,
    // otherwise the gap between two consecutive fields would grow.
    $gaps = $page->script(<<<'JS'
        (() => {
            const properties = document.querySelector('[data-test="issue-properties"]');
            const fields = Array.from(properties.children).map((field) => field.getBoundingClientRect());
            return fields.slice(1).map((rect, index) => rect.top - fields[index].bottom);
        })()
        JS);

    expect($gaps)->not->toBeEmpty();
    expect((float) max($gaps))->toBeLessThan(20);

    $page->assertNoSmoke();
});

it('opens the board create dialog wide, with every property on the right', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit("/projects/{$project->id}/board");

    $page->click('button:has-text("New issue")');
    $page->assertPresent('[data-test="issue-properties"]');

    $layout = $page->script(ISSUE_CREATE_MODAL_PROBE);

    expect($layout['panelWidth'])->toBeGreaterThanOrEqual($layout['viewportWidth'] * 0.7);
    expect($layout['propertiesLeft'])->toBeGreaterThan($layout['descriptionRight']);
    expect(abs($layout['propertiesTop'] - $layout['redactionTop']))->toBeLessThan(40);

    // Every property lives in the right column, from status to estimate.
    $properties = $page->script(<<<'JS'
        (() => {
            const properties = document.querySelector('[data-test="issue-properties"]');
            return {
                status: properties.contains(document.querySelector('[data-test="board-issue-status"]')),
                due: properties.contains(document.querySelector('#board-issue-due')),
                estimate: properties.contains(document.querySelector('#board-issue-estimate')),
            };
        })()
        JS);

    expect($properties)->toBe(['status' => true, 'due' => true, 'estimate' => true]);

    $page->assertNoSmoke();
});
