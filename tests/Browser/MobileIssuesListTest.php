<?php

use App\Actions\Issues\CreateIssue;
use App\Support\WorkspaceContext;

it('shows the issues as adaptive cards on mobile and keeps the table on desktop', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $issue = CreateIssue::run($workspace, ['project_id' => $project->id, 'title' => 'Carte mobile']);
    $this->actingAs($owner);

    // Mobile: one card per issue, no visible table.
    $mobile = visit('/issues')->on()->mobile();
    $mobile->assertPresent("[data-test='issue-card-{$issue->id}']")
        ->assertPresent("[data-test='issue-card-{$issue->id}'] [data-test='issue-title-{$issue->id}']")
        ->assertScript("document.querySelector('[data-slot=\"table\"]').offsetParent === null")
        ->assertScript("document.querySelector('[data-test=\"issues-card-list\"]').offsetParent !== null")
        ->assertVisible('@filter-bar')
        ->assertVisible('@filter-search')
        ->assertNoSmoke();

    // Desktop: the table, unchanged — the card list stays hidden.
    $desktop = visit('/issues');
    $desktop->assertPresent("[data-test='issue-row-{$issue->id}']")
        ->assertScript("document.querySelector('[data-slot=\"table\"]').offsetParent !== null")
        ->assertScript("getComputedStyle(document.querySelector('[data-test=\"issues-card-list\"]')).display === 'none'")
        ->assertNoSmoke();
});

it('keeps the filters and the pagination usable on a mobile viewport', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/issues')->on()->mobile();

    // The search field spans the viewport and the filter chips wrap below.
    $page->assertVisible('@filter-bar')
        ->assertVisible('@filter-count')
        ->assertVisible('@filter-select-category')
        ->assertScript("document.querySelector('[data-test=\"filter-search\"]').getBoundingClientRect().width > 200")
        ->assertNoSmoke();
});
