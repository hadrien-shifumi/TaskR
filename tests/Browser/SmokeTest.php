<?php

use App\Support\WorkspaceContext;

it('renders the login page without smoke', function () {
    $page = visit('/login');

    $page->assertTitleContains('Log in')
        ->assertSee('Log in')
        ->assertSee('Access your workspaces.')
        ->assertNoSmoke();
});

it('renders every page without smoke or serious accessibility issues', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $project = seedProjectWithHealth($workspace, $owner);
    app(WorkspaceContext::class)->set($workspace);
    $issue = $workspace->issues()->firstOrFail();

    // Guest pages first: an actingAs user would be redirected away.
    $page = visit('/login');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $page->navigate('/register');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $this->actingAs($owner);

    $page->navigate('/');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $page->navigate('/projects');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $page->navigate('/issues');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $page->navigate('/issues/'.$issue->id);
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $page->navigate('/projects/'.$project->id.'/health');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    $page->navigate('/settings/statuses');
    $page->assertNoSmoke()->assertNoAccessibilityIssues();

    // Scenario "Toutes les pages sans fumée": same coverage on a mobile viewport.
    $mobile = visit('/')->on()->mobile();
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();

    $mobile->navigate('/projects');
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();

    $mobile->navigate('/issues');
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();

    $mobile->navigate('/issues/'.$issue->id);
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();

    $mobile->navigate('/projects/'.$project->id.'/health');
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();

    $mobile->navigate('/settings/statuses');
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();

    $mobile->navigate('/login');
    $mobile->assertNoSmoke()->assertNoAccessibilityIssues();
});
