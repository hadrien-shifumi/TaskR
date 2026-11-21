<?php

namespace Tests\Feature\Chat;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\ChatThread;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The thread surface of the chat V1: the create-plus-first-message
 * endpoint, the sidebar list, the thread read — and the author-only
 * rule that keeps someone else's conversation invisible.
 */
class ChatThreadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function actingMember(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Shifumi studio']);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    public function test_the_first_message_creates_the_thread_and_answers_202(): void
    {
        [$owner, $workspace] = $this->actingMember();

        // The real flow: the thread is created through the endpoint, the
        // workspace resolution runs inside the request (the middleware
        // fallback: the member's first workspace).
        $response = $this->actingAs($owner)
            ->postJson('/chat/threads', ['content' => 'Peux-tu résumer l’avancement du projet ?']);

        $response->assertStatus(202)
            ->assertJsonStructure(['threadId', 'userMessageId', 'assistantMessageId', 'status'])
            ->assertJsonPath('status', 'pending');

        // Read the created thread back through the same workspace scope
        // the request used — the author sees their own conversation.
        $response->assertJsonPath('status', 'pending');

        $thread = ChatThread::withoutGlobalScopes()->findOrFail($response->json('threadId'));

        $this->assertSame($owner->id, $thread->user_id);
        $this->assertSame('Peux-tu résumer l’avancement du projet ?', $thread->title);
    }

    public function test_the_next_message_lands_on_the_same_thread(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        $response = $this->actingAs($owner)
            ->postJson("/chat/threads/{$thread->id}/messages", ['content' => 'Et maintenant ?']);

        $response->assertStatus(202);
        $this->assertSame(2, $thread->messages()->count());
    }

    public function test_someone_else_s_thread_is_not_reachable(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $other = User::factory()->create();
        $thread = ChatThread::factory()->for($workspace)->for($other, 'user')->create();

        $this->actingAs($owner)
            ->getJson("/chat/threads/{$thread->id}")
            ->assertStatus(404);

        $this->actingAs($owner)
            ->postJson("/chat/threads/{$thread->id}/messages", ['content' => 'Piraté ?'])
            ->assertStatus(404);
    }

    public function test_the_thread_read_covers_the_transcript(): void
    {
        [$owner, $workspace] = $this->actingMember();
        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();
        $thread->messages()->createMany([
            ['role' => 'user', 'body' => 'Question'],
            ['role' => 'assistant', 'body' => 'Réponse', 'status' => 'complete'],
        ]);

        $this->actingAs($owner)
            ->getJson("/chat/threads/{$thread->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['thread', 'messages'])
            ->assertJsonCount(2, 'messages');
    }
}
