<?php

namespace Tests\Feature;

use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Projects\CreateProject;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\KnowledgePage;
use App\Models\Membership;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeCreatePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Owner + dedicated workspace, the minimal cast for the create flow.
     *
     * @return array{0: User, 1: Workspace}
     */
    private function setupWorkspace(): array
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_create_page_renders_for_authenticated_member(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->get(route('knowledge.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Knowledge/Create')
                ->has('projects')
            );
    }

    public function test_create_page_requires_authentication(): void
    {
        $this->get(route('knowledge.create'))->assertRedirect(route('login'));
    }

    public function test_create_page_carries_prefill_props(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $project = CreateProject::run($workspace, $owner, ['name' => 'Facturation']);
        $parent = CreateKnowledgePage::run($workspace, $owner, [
            'title' => 'Guide racine',
            'body' => 'Corps du guide.',
        ]);

        $this->actingAs($owner)
            ->get(route('knowledge.create', ['project' => $project->id, 'parent' => $parent->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('prefillProject', $project->id)
                ->where('prefillParent', $parent->id)
                ->where('prefillParentTitle', 'Guide racine')
            );
    }

    public function test_valid_submission_creates_page_and_redirects_to_the_reader(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();

        $response = $this->actingAs($owner)->post(route('knowledge.store'), [
            'title' => 'Glossaire métier',
            'body' => 'Vocabulaire du domaine.',
        ]);

        $page = KnowledgePage::query()->where('title', 'Glossaire métier')->firstOrFail();
        $response->assertRedirect(route('knowledge.show', $page));
    }

    public function test_invalid_submission_returns_422_with_field_errors(): void
    {
        [$owner] = $this->setupWorkspace();

        $this->actingAs($owner)
            ->post(route('knowledge.store'), ['title' => '', 'body' => ''])
            ->assertInvalid(['title' => 'required', 'body' => 'required']);
    }

    public function test_read_only_guest_cannot_create(): void
    {
        [$owner, $workspace] = $this->setupWorkspace();
        $guest = User::factory()->create();
        Membership::create([
            'user_id' => $guest->id,
            'workspace_id' => $workspace->id,
            'role' => 'guest',
        ]);

        $this->actingAs($guest)
            ->post(route('knowledge.store'), [
                'title' => 'Sneaky page',
                'body' => 'Corps',
            ])
            ->assertInvalid(['project_id']);

        $this->assertSame(0, KnowledgePage::query()->count());
    }
}
