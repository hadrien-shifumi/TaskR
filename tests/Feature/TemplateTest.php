<?php

namespace Tests\Feature;

use App\Actions\Issues\CreateIssue;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\CreateProject;
use App\Actions\Templates\CreateIssueTemplate;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\IssueTemplate;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $project = CreateProject::run($workspace, $owner, ['name' => 'Site vitrine']);

        $member = User::factory()->create();
        Membership::create([
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        return [$owner, $workspace, $project, $member];
    }

    public function test_owner_can_create_full_template(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $front = CreateLabel::run($workspace, ['name' => 'front']);
        $body = "## Contexte\n## Étapes de reproduction\n## Comportement attendu";

        $this->actingAs($owner)
            ->post('/templates', [
                'name' => 'Bug',
                'description' => $body,
                'priority' => 'high',
                'label_ids' => [$bug->id, $front->id],
            ])
            ->assertRedirect(route('settings.templates'));

        $template = IssueTemplate::query()->where('name', 'Bug')->firstOrFail();

        $this->assertSame($workspace->id, $template->workspace_id);
        $this->assertSame($body, $template->description);
        $this->assertSame('high', $template->priority->value);
        $this->assertSame([$bug->id, $front->id], $template->labels()->pluck('labels.id')->all());
    }

    public function test_owner_can_create_minimal_template(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/templates', ['name' => 'Analyse fonctionnelle'])
            ->assertRedirect(route('settings.templates'));

        $template = IssueTemplate::query()->where('name', 'Analyse fonctionnelle')->firstOrFail();

        $this->assertNull($template->description);
        $this->assertNull($template->priority);
        $this->assertSame([], $template->labels()->pluck('labels.id')->all());
    }

    public function test_template_name_must_be_unique_per_workspace(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post('/templates', ['name' => 'Bug'])
            ->assertRedirect(route('settings.templates'));

        $this->actingAs($owner)
            ->post('/templates', ['name' => 'Bug'])
            ->assertSessionHasErrors('name');

        // The same name is fine in another workspace.
        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);

        $this->actingAs($other)
            ->post('/templates', ['name' => 'Bug'])
            ->assertRedirect(route('settings.templates'));

        $this->assertSame(2, IssueTemplate::withoutGlobalScope(WorkspaceScope::class)->count());
    }

    public function test_member_cannot_manage_templates(): void
    {
        [$owner, $workspace, , $member] = $this->setupWorkspace();

        $template = IssueTemplate::create(['name' => 'Bug']);

        $this->actingAs($member)->post('/templates', ['name' => 'X'])->assertForbidden();
        $this->actingAs($member)->patch("/templates/{$template->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($member)->delete("/templates/{$template->id}")->assertForbidden();

        // Reading the list stays open: the creation selector needs it.
        $this->actingAs($member)->get('/settings/templates')->assertOk();
    }

    public function test_templates_page_lists_templates(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $label = CreateLabel::run($workspace, ['name' => 'bug']);
        IssueTemplate::create([
            'name' => 'Bug',
            'description' => '## Contexte',
            'priority' => 'high',
        ])->labels()->sync([$label->id]);
        IssueTemplate::create(['name' => 'Analyse fonctionnelle']);

        $this->actingAs($owner)
            ->get('/settings/templates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Templates')
                ->has('templates', 2)
                ->where('templates.0.name', 'Analyse fonctionnelle')
                ->where('templates.1.name', 'Bug')
                ->where('templates.1.description', '## Contexte')
                ->where('templates.1.priority', 'high')
                ->where('templates.1.labels.0.id', $label->id)
                ->has('labels', 1));
    }

    public function test_owner_can_update_template(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $old = CreateLabel::run($workspace, ['name' => 'old']);
        $new = CreateLabel::run($workspace, ['name' => 'new']);

        $template = IssueTemplate::create([
            'name' => 'Bug',
            'description' => 'Old body',
            'priority' => 'low',
        ]);
        $template->labels()->sync([$old->id]);

        $this->actingAs($owner)
            ->patch("/templates/{$template->id}", [
                'name' => 'Bug report',
                'description' => 'New body',
                'priority' => 'urgent',
                'label_ids' => [$new->id],
            ])
            ->assertRedirect(route('settings.templates'));

        $template->refresh();

        $this->assertSame('Bug report', $template->name);
        $this->assertSame('New body', $template->description);
        $this->assertSame('urgent', $template->priority->value);
        $this->assertSame([$new->id], $template->labels()->pluck('labels.id')->all());

        // Renaming to its own name is not a duplicate.
        $this->actingAs($owner)
            ->patch("/templates/{$template->id}", ['name' => 'Bug report'])
            ->assertRedirect(route('settings.templates'));
    }

    public function test_owner_can_delete_template(): void
    {
        [$owner] = $this->setupWorkspace();

        $template = IssueTemplate::create(['name' => 'Bug']);

        $this->actingAs($owner)
            ->delete("/templates/{$template->id}")
            ->assertRedirect(route('settings.templates'));

        $this->assertDatabaseMissing('issue_templates', ['id' => $template->id]);
    }

    public function test_cross_workspace_template_access_is_refused(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $template = IssueTemplate::create(['name' => 'Bug']);

        $intruder = User::factory()->create();
        $intruderWorkspace = CreateWorkspace::run($intruder, ['name' => 'Theirs']);
        app(WorkspaceContext::class)->set($intruderWorkspace);

        $this->actingAs($intruder)
            ->patch("/templates/{$template->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->actingAs($intruder)
            ->delete("/templates/{$template->id}")
            ->assertNotFound();

        // The intruder's own list does not leak workspace A templates.
        $this->actingAs($intruder)
            ->get('/settings/templates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('templates', 0));
    }

    public function test_template_prefills_issue_creation(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $body = "## Contexte\n## Étapes de reproduction\n## Comportement attendu";

        $template = CreateIssueTemplate::run($workspace, [
            'name' => 'Bug',
            'description' => $body,
            'priority' => 'high',
            'label_ids' => [$bug->id],
        ]);

        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Écran blanc au login',
                'template' => $template->id,
            ])
            ->assertRedirect();

        $issue = $workspace->issues()->where('title', 'Écran blanc au login')->firstOrFail();

        $this->assertSame($body, $issue->description);
        $this->assertSame('high', $issue->priority->value);
        $this->assertSame([$bug->id], $issue->labels()->pluck('labels.id')->all());
    }

    public function test_explicit_values_win_over_template(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $other = CreateLabel::run($workspace, ['name' => 'other']);

        $template = CreateIssueTemplate::run($workspace, [
            'name' => 'Bug',
            'description' => '## Corps du gabarit',
            'priority' => 'high',
            'label_ids' => [$bug->id],
        ]);

        // Explicit priority and labels beat the template defaults...
        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Priorité explicite',
                'template' => $template->id,
                'priority' => 'urgent',
                'label_ids' => [$other->id],
            ])
            ->assertRedirect();

        $issue = $workspace->issues()->where('title', 'Priorité explicite')->firstOrFail();

        $this->assertSame('urgent', $issue->priority->value);
        $this->assertSame([$other->id], $issue->labels()->pluck('labels.id')->all());

        // ...and a present-but-empty key counts as explicit: no template
        // description, no template labels. This also covers the client-side
        // pre-fill path: explicit values make the server-side merge a no-op
        // (double application impossible).
        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Champs vides explicites',
                'template' => $template->id,
                'description' => '',
                'label_ids' => [],
            ])
            ->assertRedirect();

        $empty = $workspace->issues()->where('title', 'Champs vides explicites')->firstOrFail();

        $this->assertNull($empty->description);
        $this->assertSame([], $empty->labels()->pluck('labels.id')->all());
    }

    public function test_issue_without_template_is_unchanged(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        CreateIssueTemplate::run($workspace, ['name' => 'Bug', 'description' => '## Corps', 'priority' => 'high']);

        $this->actingAs($owner)
            ->post('/issues', ['project_id' => $project->id, 'title' => 'Sans gabarit'])
            ->assertRedirect();

        $issue = $workspace->issues()->where('title', 'Sans gabarit')->firstOrFail();

        $this->assertNull($issue->description);
        $this->assertSame('none', $issue->priority->value);
        $this->assertSame([], $issue->labels()->pluck('labels.id')->all());
    }

    public function test_template_from_another_workspace_is_rejected(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $other = User::factory()->create();
        $otherWorkspace = CreateWorkspace::run($other, ['name' => 'Other']);
        app(WorkspaceContext::class)->set($otherWorkspace);
        $foreignTemplate = CreateIssueTemplate::run($otherWorkspace, ['name' => 'Bug']);

        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Intrusion',
                'template' => $foreignTemplate->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, $workspace->issues()->count());
    }

    public function test_member_can_apply_template_when_creating_issue(): void
    {
        [, $workspace, $project, $member] = $this->setupWorkspace();

        $template = CreateIssueTemplate::run($workspace, [
            'name' => 'Analyse fonctionnelle',
            'description' => '## Objectifs',
            'priority' => 'low',
        ]);

        $this->actingAs($member)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Analyse paiements',
                'template' => $template->id,
            ])
            ->assertRedirect();

        $issue = $workspace->issues()->where('title', 'Analyse paiements')->firstOrFail();

        $this->assertSame('## Objectifs', $issue->description);
        $this->assertSame('low', $issue->priority->value);
    }

    public function test_deleting_template_does_not_affect_existing_issues(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $template = CreateIssueTemplate::run($workspace, [
            'name' => 'Bug',
            'description' => '## Corps',
            'priority' => 'high',
            'label_ids' => [$bug->id],
        ]);

        $issue = CreateIssue::run($workspace, [
            'project_id' => $project->id,
            'title' => 'Issue pérenne',
            'template' => $template->id,
        ]);

        $this->actingAs($owner)
            ->delete("/templates/{$template->id}")
            ->assertRedirect(route('settings.templates'));

        $issue->refresh();

        $this->assertSame('## Corps', $issue->description);
        $this->assertSame('high', $issue->priority->value);
        $this->assertSame([$bug->id], $issue->labels()->pluck('labels.id')->all());
    }

    public function test_template_labels_that_no_longer_exist_are_ignored(): void
    {
        [$owner, $workspace, $project] = $this->setupWorkspace();

        $alive = CreateLabel::run($workspace, ['name' => 'alive']);
        $stale = CreateLabel::run($workspace, ['name' => 'stale']);

        $template = CreateIssueTemplate::run($workspace, [
            'name' => 'Bug',
            'label_ids' => [$alive->id, $stale->id],
        ]);

        // Label deletion does not exist in the app yet; simulate it directly.
        Label::query()->whereKey($stale->id)->delete();

        $this->actingAs($owner)
            ->post('/issues', [
                'project_id' => $project->id,
                'title' => 'Gabarit pourri',
                'template' => $template->id,
            ])
            ->assertRedirect();

        $issue = $workspace->issues()->where('title', 'Gabarit pourri')->firstOrFail();

        $this->assertSame([$alive->id], $issue->labels()->pluck('labels.id')->all());
    }

    public function test_issues_page_exposes_templates_for_selector(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        CreateIssueTemplate::run($workspace, [
            'name' => 'Bug',
            'description' => '## Contexte',
            'priority' => 'high',
            'label_ids' => [$bug->id],
        ]);

        $this->actingAs($owner)
            ->get('/issues')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('templates', 1)
                ->where('templates.0.name', 'Bug')
                ->where('templates.0.description', '## Contexte')
                ->where('templates.0.priority', 'high')
                ->where('templates.0.labels.0.id', $bug->id));
    }

    /**
     * Change task 6.4 — minimal dogfood: define the "Bug" and
     * "Analyse fonctionnelle" templates, create an issue through each,
     * freely edit pre-filled values, and check a template-less issue
     * stays untouched.
     */
    public function test_dogfood_bug_and_analysis_templates(): void
    {
        [$owner, $workspace, $project, $member] = $this->setupWorkspace();

        $bug = CreateLabel::run($workspace, ['name' => 'bug']);
        $body = "## Contexte\n## Étapes de reproduction\n## Comportement attendu";

        $bugTemplate = CreateIssueTemplate::run($workspace, [
            'name' => 'Bug',
            'description' => $body,
            'priority' => 'high',
            'label_ids' => [$bug->id],
        ]);
        $analysisTemplate = CreateIssueTemplate::run($workspace, [
            'name' => 'Analyse fonctionnelle',
            'description' => '## Objectifs\n## Périmètre',
        ]);

        // One issue through each template.
        $this->actingAs($member)->post('/issues', [
            'project_id' => $project->id,
            'title' => 'Erreur 500 sur /register',
            'template' => $bugTemplate->id,
        ])->assertRedirect();

        $this->actingAs($member)->post('/issues', [
            'project_id' => $project->id,
            'title' => 'Analyse du module paiements',
            'template' => $analysisTemplate->id,
        ])->assertRedirect();

        $reported = $workspace->issues()->where('title', 'Erreur 500 sur /register')->firstOrFail();
        $analysis = $workspace->issues()->where('title', 'Analyse du module paiements')->firstOrFail();

        $this->assertSame($body, $reported->description);
        $this->assertSame('high', $reported->priority->value);
        $this->assertSame('## Objectifs\n## Périmètre', $analysis->description);
        $this->assertSame('none', $analysis->priority->value);

        // Pre-filled values stay freely editable after creation.
        $this->actingAs($member)
            ->patch("/issues/{$reported->id}", ['title' => 'Erreur 500 sur /register', 'description' => 'Corrigé.', 'priority' => 'urgent'])
            ->assertRedirect();
        $this->actingAs($member)
            ->post("/issues/{$reported->id}/labels", ['label_ids' => []])
            ->assertRedirect();

        $reported->refresh();
        $this->assertSame('Corrigé.', $reported->description);
        $this->assertSame('urgent', $reported->priority->value);
        $this->assertSame([], $reported->labels()->pluck('labels.id')->all());

        // A template-less issue is unaffected by the templates' existence.
        $this->actingAs($member)->post('/issues', [
            'project_id' => $project->id,
            'title' => 'Note libre',
        ])->assertRedirect();

        $free = $workspace->issues()->where('title', 'Note libre')->firstOrFail();
        $this->assertNull($free->description);
        $this->assertSame('none', $free->priority->value);
    }
}
