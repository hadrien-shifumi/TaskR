<?php

use App\Support\WorkspaceContext;

it('shows the project health overview badge and the health page indicators', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $project = seedProjectWithHealth($workspace, $owner);
    $this->actingAs($owner);

    $page = visit('/projects');

    // Scenario "Badge de statut sur la liste" (project-health).
    $page->assertSee('Late');

    $page->click('li:has-text("Refonte site vitrine") button:has-text("Health")');

    // Scenario "Consultation de la page Health" + "Répartition par catégorie".
    $page->assertSee('Health — Refonte site vitrine');
    $page->assertSeeIn('[data-slot=card]:has([data-slot=card-description]:text-is("Backlog")) [data-slot=card-title]', '3');
    $page->assertSeeIn('[data-slot=card]:has([data-slot=card-description]:text-is("In Progress")) [data-slot=card-title]', '2');
    $page->assertSeeIn('[data-slot=card]:has([data-slot=card-description]:text-is("Done")) [data-slot=card-title]', '4');
    $page->assertSeeIn('[data-slot=card]:has([data-slot=card-description]:text-is("Cancelled")) [data-slot=card-title]', '1');
    $page->assertSee('50%')
        ->assertSee('10 issues total');

    // Scenario "Issue due dépassée".
    $page->assertSeeIn('[data-slot=card]:has([data-slot=card-description]:text-is("Overdue issues")) [data-slot=card-title]', '1');

    // Scenario "Consommation à 80 %".
    $page->assertSee('8.0h / 10.0h')
        ->assertSee('80% of estimated hours consumed');

    // Scenario "Projet en retard": target date passed, 50% done, expected 100%.
    $page->assertSee('Expected at this date: 100%');

    app(WorkspaceContext::class)->set($workspace);
    expect($project->name)->toBe('Refonte site vitrine');

    $page->assertNoSmoke();
});
