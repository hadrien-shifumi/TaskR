<?php

namespace Tests\Feature\Chat;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\ChatThread;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The instance-wide feature flag of the Chatbot section (the owner's
 * decision of 2026-11-21 to keep the section out of sight until it is
 * explicitly re-opened). Hidden is a visibility matter, never a security
 * one: the section pages answer 404 — nothing hints they exist — while
 * the JSON chat endpoints stay untouched (the threads remain the user's
 * data), and the flag travels to the front so the nav items drops.
 */
class ChatbotFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private function actingMember(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Shifumi studio']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_the_chatbot_section_is_404_by_default(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('chatbot.show', $thread))
            ->assertNotFound();
    }

    public function test_the_chatbot_section_serves_its_pages_when_the_flag_is_on(): void
    {
        config()->set('taskr.features.chat', true);

        [$owner, $workspace] = $this->actingMember();
        app(WorkspaceContext::class)->set($workspace);
        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Index'));

        $this->actingAs($owner)
            ->get(route('chatbot.show', $thread))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Show'));
    }

    public function test_the_feature_flag_travels_to_the_front_as_a_shared_prop(): void
    {
        [$owner] = $this->actingMember();

        // Off (the default): any ordinary page shares the falsy flag —
        // the nav needs no extra request to know the item must not render.
        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('features.chat', false));

        // On: the same prop flips to true.
        config()->set('taskr.features.chat', true);

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('features.chat', true));
    }

    public function test_the_json_chat_endpoints_stay_open_while_the_section_is_hidden(): void
    {
        [$owner] = $this->actingMember();

        // The flag hides the section, it is not a security gate: the
        // threads belong to their author and the JSON contract stays.
        $this->actingAs($owner)
            ->postJson('/chat/threads', ['content' => 'Peux-tu résumer l’avancement du projet ?'])
            ->assertStatus(202)
            ->assertJsonStructure(['threadId', 'status']);
    }
}
