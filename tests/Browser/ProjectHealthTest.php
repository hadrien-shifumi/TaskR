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
    // project name, its computed health badge and the target date meta.
    $page->assertSee('Issues distribution')
        ->assertSee('Late issues in this project');

    // The intro states the indicators are derived, never hand-entered.
    $page->assertSee('All indicators below are calculated from the issues');

    // Pagebar meta: the target date is passed.
    $page->assertSeeIn('@pagebar-meta', 'target:')
        ->assertSeeIn('@pagebar-meta', 'overdue');

    // Scenario "Répartition par catégorie": 3 backlog, 2 in progress, 4 done, 1 cancelled.
    $page->assertSeeIn('@dist-backlog', '3');
    $page->assertSeeIn('@dist-in_progress', '2');
    $page->assertSeeIn('@dist-done', '4');
    $page->assertSeeIn('@dist-cancelled', '1');

    // Completion: 4 done of 10 issues = 40% — cancelled issues count in the
    // total but not as done (maquette: "12 done + 1 cancelled sur 17" → 71 %).
    $page->assertSeeIn('@stat-completed', '40 %')
        ->assertSee('4 done + 1 cancelled of 10 issues');

    // Scenario "Issue due dépassée".
    $page->assertSeeIn('@stat-overdue', '1');

    // Scenario "Consommation à 80 %".
    $page->assertSeeIn('@stat-hours', '8 / 10 h')
        ->assertSee('logged / estimated · 80% consumed');

    // Scenario "Projet en retard": target date passed, 40% done, expected 100%.
    $page->assertSeeIn('@stat-projection', '40% done · 100% expected at this date');

    // The late issues table exposes the overdue issue.
    $page->assertSee('IP1');

    // Aside projection: done vs expected at this date.
    $page->assertSeeIn('@projection-done', '40%')
        ->assertSeeIn('@projection-expected', '100%');

    app(WorkspaceContext::class)->set($workspace);
    expect($project->name)->toBe('Refonte site vitrine');

    $page->assertNoSmoke();
});

it('recalculates the projection when the target date is edited', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $project = seedProjectWithHealth($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/projects/'.$project->id.'/health');

    // The project starts late: target date passed, 100% expected at this date.
    $page->assertSeeIn('@stat-projection', '100% expected at this date');

    // Scenario "Édition de la date cible": moving the target date forward
    // recalculates the projection immediately (projects.update).
    $newTarget = now()->addDays(60)->toDateString();
    $page->fill('@target-date-input', $newTarget)
        ->click('@recalculate-projection')
        ->wait(1);

    // The recalculated projection renders on the health page itself: nothing
    // has elapsed yet, so 0% is expected and the project is back on track.
    $page->assertPathIs('/projects/'.$project->id.'/health')
        ->assertSeeIn('@stat-projection', '40% done · 0% expected at this date')
        ->assertSee('On track')
        ->assertSeeIn('@pagebar-meta', 'target:')
        ->assertDontSeeIn('@pagebar-meta', 'overdue');

    app(WorkspaceContext::class)->set($workspace);
    expect($project->fresh()->target_date->toDateString())->toBe($newTarget);

    $page->assertNoSmoke();
});
