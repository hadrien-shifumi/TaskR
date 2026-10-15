<?php

use App\Support\WorkspaceContext;

it('shows the project health overview badge and the health page indicators', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $project = seedProjectWithHealth($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/projects');

    // Scenario "Badge de statut sur la liste" (project-health).
    $page->assertSee('Late');

    // The project name is the row's link to its health page.
    $page->click('tr:has-text("Refonte site vitrine") a:has-text("Refonte site vitrine")');

    // Scenario "Consultation de la page Health": the pagebar carries the
    // project name and its computed health badge.
    $page->assertSee('Issues distribution')
        ->assertSee('Late issues in this project');

    // Scenario "Répartition par catégorie": 3 backlog, 2 in progress, 4 done, 1 cancelled.
    $page->assertSeeIn('@dist-backlog', '3');
    $page->assertSeeIn('@dist-in_progress', '2');
    $page->assertSeeIn('@dist-done', '4');
    $page->assertSeeIn('@dist-cancelled', '1');

    // Completion: 4 done + 1 cancelled of 10 issues = 50%.
    $page->assertSeeIn('@stat-completed', '50%')
        ->assertSee('of 10 issues');

    // Scenario "Issue due dépassée".
    $page->assertSeeIn('@stat-overdue', '1');

    // Scenario "Consommation à 80 %".
    $page->assertSeeIn('@stat-hours', '8.0h / 10.0h')
        ->assertSee('80% of estimated hours consumed');

    // Scenario "Projet en retard": target date passed, 50% done, expected 100%.
    $page->assertSeeIn('@stat-projection', '100% expected at this date');

    // The late issues table exposes the overdue issue.
    $page->assertSee('IP1');

    app(WorkspaceContext::class)->set($workspace);
    expect($project->name)->toBe('Refonte site vitrine');

    $page->assertNoSmoke();
});
