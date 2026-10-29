<?php

use App\Actions\Labels\CreateLabel;
use App\Actions\Templates\CreateIssueTemplate;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;

it('lets an owner create and delete a template from the UI', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/templates');

    // The create dialog opens with the i18n default markdown body pre-filled.
    $page->click('New template');
    $page->fill('new-template-name', 'Gabarit déploiement');
    // "## Context" prefixes both the fr ("## Contexte") and en default bodies;
    // the editor renders the markdown, so the assertion targets the rendered heading.
    $page->assertSeeIn('[data-slot="markdown-editor"]', 'Context');
    $page->click('[role="combobox"]');
    $page->click('[role="option"]:has-text("high")');
    $page->click('button[type="submit"]');

    $page->assertSee('Template "Gabarit déploiement" created.')
        ->assertSee('Gabarit déploiement');

    app(WorkspaceContext::class)->set($workspace);
    $template = $workspace->issueTemplates()->where('name', 'Gabarit déploiement')->firstOrFail();

    expect($template->priority?->value)->toBe('high')
        ->and($template->description)->toStartWith('## Context');

    // Delete it through the row's actions menu (confirm auto-accepted).
    $page->script('window.confirm = () => true');
    $page->click('button[aria-label="Actions for template Gabarit déploiement"]');
    $page->click('Delete…');

    $page->assertSee('Template deleted.')
        ->assertDontSee('Gabarit déploiement')
        ->assertSee('No templates yet.');

    app(WorkspaceContext::class)->set($workspace);
    expect($workspace->issueTemplates()->where('name', 'Gabarit déploiement')->exists())->toBeFalse();

    $page->assertNoSmoke();
});

it('shows templates read-only to a member', function () {
    [$owner, $workspace] = setupBrowserWorkspace();
    app(WorkspaceContext::class)->set($workspace);

    CreateIssueTemplate::run($workspace, [
        'name' => 'Gabarit incident',
        'description' => 'Corps du gabarit',
        'priority' => 'high',
    ]);

    $member = User::factory()->create();
    Membership::create([
        'user_id' => $member->id,
        'workspace_id' => $workspace->id,
        'role' => 'member',
    ]);
    $this->actingAs($member);

    $page = visit('/settings/templates');

    // The list stays readable (the creation selector needs it), but every
    // owner-only control is not rendered.
    $page->assertSee('Gabarit incident')
        ->assertSee('Corps du gabarit');
    $page->assertMissing('button:has-text("New template")');
    $page->assertMissing('button[aria-label="Actions for template Gabarit incident"]');

    app(WorkspaceContext::class)->set($workspace);
    expect($workspace->issueTemplates()->count())->toBe(1);

    $page->assertNoSmoke();
});

it('prefills the create-issue dialog from a template and keeps the issue independent', function () {
    [$owner, $workspace, $project] = setupBrowserWorkspace(withProject: true);
    app(WorkspaceContext::class)->set($workspace);

    $bug = CreateLabel::run($workspace, ['name' => 'bug']);
    $front = CreateLabel::run($workspace, ['name' => 'front']);
    $template = CreateIssueTemplate::run($workspace, [
        'name' => 'Gabarit bug',
        'description' => 'Corps standard du gabarit',
        'priority' => 'high',
        'label_ids' => [$bug->id, $front->id],
    ]);
    $this->actingAs($owner);

    $page = visit('/issues');

    $page->click('New issue');
    $page->fill('issue-title', 'Écran blanc au login');
    $page->click('[role="combobox"]:has-text("No template")');
    $page->click('[role="option"]:has-text("Gabarit bug")');

    // Template defaults land in the form, visibly and still editable.
    $page->assertSeeIn('[data-slot="markdown-editor"]', 'Corps standard du gabarit');
    $page->assertSeeIn('[role="combobox"]:has-text("high")', 'high');
    $page->assertPresent('button[aria-pressed="true"]:has-text("bug")');
    $page->assertPresent('button[aria-pressed="true"]:has-text("front")');

    // The pre-filled description is freely editable before submitting.
    $page->fill('[data-slot="markdown-editor"] .ProseMirror', 'Corps modifié à la main');
    $page->click('button[type="submit"]');

    $page->assertSee('Issue created.')
        ->assertSee('Écran blanc au login');

    // The issue stores the edited values, not a live link to the template.
    app(WorkspaceContext::class)->set($workspace);
    $issue = $workspace->issues()->where('title', 'Écran blanc au login')->firstOrFail();

    expect($issue->description)->toBe('Corps modifié à la main')
        ->and($issue->priority->value)->toBe('high')
        ->and($issue->labels->pluck('name')->sort()->values()->all())->toBe(['bug', 'front']);

    // Deleting the template never touches existing issues.
    $template->delete();

    $page = visit('/issues/'.$issue->id);

    $page->assertSee('Corps modifié à la main')
        ->assertDontSee('Corps standard du gabarit');

    $page->assertNoSmoke();
});
