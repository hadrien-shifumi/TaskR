<?php

namespace Tests\Feature\Chat;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\ChatThread;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Chatbot section's Inertia pages: the conversations list and the
 * read of one conversation, as the Inertia contract the React pages
 * consume. Author-only like every chat surface — someone else's thread
 * fails exactly like a missing one — and the "chat is disabled" state,
 * a rendered banner on the pages, never a hidden crash.
 */
class ChatbotPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The section sleeps unless the instance flag says otherwise:
        // these tests cover the pages themselves, so they run with the
        // flag on (the hidden-when-off behavior has its own suite).
        config()->set('taskr.features.chat', true);
    }

    protected function tearDown(): void
    {
        config()->set('taskr.features.chat', false);

        parent::tearDown();
    }

    private function actingMember(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Shifumi studio']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_the_chatbot_landing_page_lists_the_member_threads(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Index')
                ->where('threads.0.id', $thread->id)
                ->where('threads.0.title', $thread->title)
                ->has('threads.0.createdAt')
                ->has('threads.0.updatedAt')
                ->where('chatEnabled', false));
    }

    public function test_the_chatbot_landing_page_orders_the_most_recently_active_first(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $older = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        // A thread touched later sits first in the landing list (the
        // sidebar follows the last activity, not the creation order).
        $this->travelTo(now()->addSeconds(5));
        $newer = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Index')
                ->where('threads.0.id', $newer->id)
                ->where('threads.1.id', $older->id));
    }

    public function test_only_the_author_s_threads_appear_on_the_landing_page(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $other = User::factory()->create();
        Membership::create([
            'user_id' => $other->id,
            'workspace_id' => $workspace->id,
            'role' => 'member',
        ]);

        $mine = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();
        ChatThread::factory()->for($workspace)->for($other, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Index')
                ->where('threads.0.id', $mine->id)
                ->has('threads', 1));
    }

    public function test_the_conversation_page_renders_the_thread_transcript(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();
        $thread->messages()->createMany([
            ['role' => 'user', 'body' => 'Question'],
            ['role' => 'assistant', 'body' => 'Réponse', 'status' => 'complete', 'usage' => ['total_tokens' => 120]],
        ]);

        $this->actingAs($owner)
            ->get(route('chatbot.show', $thread))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Show')
                ->where('thread.id', $thread->id)
                ->where('thread.title', $thread->title)
                ->where('messages.0.role', 'user')
                ->where('messages.0.body', 'Question')
                ->where('messages.1.role', 'assistant')
                ->where('messages.1.status', 'complete')
                ->where('messages.1.usage.total_tokens', 120)
                ->where('chatEnabled', false));
    }

    public function test_a_member_s_page_of_another_conversation_fails_like_a_missing_one(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $other = User::factory()->create();
        $thread = ChatThread::factory()->for($workspace)->for($other, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.show', $thread))
            ->assertStatus(404);
    }

    public function test_a_conversation_of_another_workspace_is_never_rendered(): void
    {
        [$owner, $currentWorkspace] = $this->actingMember();
        $otherWorkspace = CreateWorkspace::run($owner, ['name' => 'Second espace']);
        app(WorkspaceContext::class)->set($currentWorkspace);

        $foreign = ChatThread::factory()->for($otherWorkspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->withSession(['workspace_id' => $currentWorkspace->id])
            ->get(route('chatbot.show', $foreign))
            ->assertStatus(404);
    }

    public function test_the_pages_report_the_disabled_chat_without_a_provider(): void
    {
        [$owner, $workspace] = $this->actingMember();

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Index')
                ->where('chatEnabled', false));

        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.show', $thread))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Show')
                ->where('chatEnabled', false));
    }

    public function test_the_pages_report_an_enabled_chat_without_leaking_its_key(): void
    {
        [$owner, $workspace] = $this->actingMember();

        WorkspaceEmbeddingSetting::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'base_url' => 'https://embeddings.test/v1',
            'api_key' => 'embeddings-key',
            'model' => 'mistral-embed',
            'dimensions' => 1024,
            'send_dimensions' => false,
            'chat_base_url' => 'https://chat.test/v1',
            'chat_api_key' => 'chat-secret-key',
            'chat_model' => 'mistral-large-latest',
        ]);

        $this->actingAs($owner)
            ->get(route('chatbot.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Index')
                ->where('chatEnabled', true)
                // The key never appears in any payload — not even a hint
                // of it, only the enabled flag travels.
                ->missing('apiKey')
                ->missing('chatApiKey')
                ->missing('keyPresent'));

        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $this->actingAs($owner)
            ->get(route('chatbot.show', $thread))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Chatbot/Show')
                ->where('chatEnabled', true)
                ->missing('apiKey')
                ->missing('chatApiKey'));
    }
}
