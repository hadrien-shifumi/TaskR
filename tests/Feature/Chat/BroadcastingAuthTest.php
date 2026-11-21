<?php

namespace Tests\Feature\Chat;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\ChatThread;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The thread's private Mercure channel answers its author alone. The
 * subscription contract is the framework's own
 * (MercureBroadcaster::auth): one request carrying `channel_names`, one
 * 200 response whose entries carry `denied: true` when the channel
 * guard refuses — a refusal never confirms the thread's existence in a
 * 403, and an authorised subscription never leaks more than the
 * channel's own name.
 */
class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The broadcast configuration as the test found it.
     *
     * @var array<string, mixed>|null
     */
    private ?array $originalBroadcasting = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The kernel boots inside parent::setUp(), so routes/channels.php
        // has already registered this file's closures — on the null
        // connection, which phpunit.xml pins as BROADCAST_CONNECTION=null.
        // The manager keeps one channel registry per connection, so pinning
        // the default to mercure alone would make the auth request reach a
        // broadcaster with an empty registry: everyone denied, including
        // the author. Pin the connection, then re-run the production
        // registrations so the broadcaster serving the request carries the
        // real closures — the Hub URL stays on the test host (the auth
        // cookie is minted against it) and the fake secret covers HS256's
        // 32-byte minimum; nothing here talks to a hub.
        $this->originalBroadcasting = config('broadcasting');

        config([
            'broadcasting.default' => 'mercure',
            'broadcasting.connections.mercure' => [
                'driver' => 'mercure',
                'url' => 'https://localhost/.well-known/mercure',
                'secret' => 'testing-mercure-jwt-secret-0123456789abcdef0123456789abcdef',
                'subscribe_expiration' => 5,
            ],
        ]);

        require base_path('routes/channels.php');
    }

    protected function tearDown(): void
    {
        // Each test builds a fresh application, so this cannot leak today —
        // still, leave the broadcast configuration exactly as found in case
        // an application ever gets shared across tests (Octane, workers).
        config(['broadcasting' => $this->originalBroadcasting]);

        parent::tearDown();
    }

    public function test_only_the_thread_author_subscribes(): void
    {
        [$author, , $thread] = $this->actingThreadAuthor();

        $this->actingAs($author)
            ->postJson('/broadcasting/auth', [
                'channel_names' => ['private-chat-threads.'.$thread->id],
            ])
            ->assertOk()
            ->assertJsonPath('channel_names.0.name', 'private-chat-threads.'.$thread->id)
            ->assertJsonPath('channel_names.0.denied', null);
    }

    public function test_another_member_of_the_same_workspace_is_denied(): void
    {
        [$author, $workspace, $thread] = $this->actingThreadAuthor();

        $other = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $other->id, 'role' => 'member']);

        $this->actingAs($other)
            ->postJson('/broadcasting/auth', [
                'channel_names' => ['private-chat-threads.'.$thread->id],
            ])
            ->assertOk()
            ->assertJsonPath('channel_names.0.denied', true);
    }

    public function test_a_thread_of_another_workspace_is_never_confirmed(): void
    {
        // The author of a thread living in another workspace they also
        // belong to: the subscription runs against the CURRENT session
        // workspace only — the other workspace's thread stays invisible.
        [$author, $currentWorkspace] = $this->actingThreadAuthor();
        $otherWorkspace = CreateWorkspace::run($author, ['name' => 'Second espace']);
        app(WorkspaceContext::class)->set($currentWorkspace);

        $foreign = ChatThread::factory()->for($otherWorkspace)->for($author, 'user')->create();

        // The auth request re-resolves the current workspace from the
        // session — without pinning it, the middleware's fallback would
        // pick "Second espace" (first by name) and the thread would become
        // visible: the denial must come from the scope, not from an
        // accident of alphabetical order.
        $this->actingAs($author)
            ->withSession(['workspace_id' => $currentWorkspace->id])
            ->postJson('/broadcasting/auth', [
                'channel_names' => ['private-chat-threads.'.$foreign->id],
            ])
            ->assertOk()
            ->assertJsonPath('channel_names.0.denied', true);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: ChatThread}
     */
    private function actingThreadAuthor(): array
    {
        $owner = User::factory()->create();
        $workspace = CreateWorkspace::run($owner, ['name' => 'Shifumi studio']);
        app(WorkspaceContext::class)->set($workspace);

        $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create();

        return [$owner, $workspace, $thread];
    }
}
