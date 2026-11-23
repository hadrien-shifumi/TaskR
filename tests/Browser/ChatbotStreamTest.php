<?php

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\ChatThread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;

/**
 * The Chatbot section's browser behaviour: the streaming runs on a
 * native EventSource subscribed to the thread's private Mercure topic —
 * the tests install an in-page fake before the conversation page mounts
 * (the landing page carries no subscription, so the patch sits ready
 * when the visit to a thread mounts it), then drive the events the
 * backend broadcasts: fragments, tool states, the settle.
 */

/**
 * Installs the fake EventSource and the JSON-post recorder. The fake
 * captures every constructed source (its URL is the subscription's
 * topic evidence) and exposes an `emit()` helper so the test drives
 * chunks, tool states and the settle the way the live hub delivers
 * them — a default `message` SSE event whose data is Laravel's
 * broadcast envelope. Ends with a definite non-function statement: the
 * evaluator would otherwise invoke the value of the last expression.
 */
function installChatEventSourceFake($page): void
{
    $page->script(<<<'JS'
        window.__chatFake = { sources: [], authPosts: 0, turnResponses: [], installed: true };

        class FakeEventSource {
            constructor(url) {
                this.url = url;
                this.readyState = 0;
                this.closed = false;
                this.onopen = null;
                this.onerror = null;
                this.onmessage = null;
                this.listeners = {};
                window.__chatFake.sources.push(this);
            }

            addEventListener(type, listener) {
                (this.listeners[type] ??= []).push(listener);
            }

            removeEventListener(type, listener) {
                const list = this.listeners[type] ?? [];
                const index = list.indexOf(listener);
                if (index >= 0) list.splice(index, 1);
            }

            emit(event, payload) {
                const envelope = JSON.stringify({ event, payload });
                if (typeof this.onmessage === 'function') {
                    this.onmessage({ data: envelope });
                }
                for (const listener of this.listeners['message'] ?? []) {
                    listener({ data: envelope });
                }
            }

            close() {
                this.readyState = 2;
                this.closed = true;
            }
        }

        window.EventSource = FakeEventSource;

        const originalFetch = window.fetch.bind(window);
        window.fetch = (input, init) => {
            const url = typeof input === 'string' ? input : String(input.url);
            const method = String((init && init.method) || 'GET').toUpperCase();

            if (method === 'POST' && url.includes('/broadcasting/auth')) {
                window.__chatFake.authPosts += 1;
            }

            if (method === 'POST' && url.includes('/chat/threads')) {
                return originalFetch(input, init).then((response) => {
                    response.clone().json().then((body) => {
                        window.__chatFake.turnResponses.push(body);
                    }).catch(() => {});

                    return response;
                });
            }

            return originalFetch(input, init);
        };

        window.__chatFake.installed = true;
        JS);
}

/**
 * Waits (bounded) for a client-side JS predicate — used after each
 * dispatched event so React's render lands before the assertion.
 */
function waitForChatPredicate($page, string $expectation, string $subject): void
{
    $deadline = 5000;

    $result = $page->script(<<<JS
        (async () => {
            const deadline = Date.now() + {$deadline};

            while (Date.now() < deadline) {
                if ({$expectation}) {
                    return true;
                }

                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return false;
        })()
        JS);

    if ($result !== true) {
        throw new RuntimeException("Chat stream condition not met: {$subject}");
    }
}

/**
 * The workspace's chat provider, configured for the enabled path: the
 * port-1 endpoint refuses instantly — and with `Queue::fake()` the turn
 * never reaches it anyway. Only the enabled flag meets the page: the
 * key is in no prop.
 */
function configureChatProvider(Workspace $workspace): void
{
    WorkspaceEmbeddingSetting::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'embeddings-key',
        'model' => 'mistral-embed',
        'dimensions' => 1024,
        'send_dimensions' => false,
        'chat_base_url' => 'http://127.0.0.1:1/chat/completions',
        'chat_api_key' => 'chat-key',
        'chat_model' => 'mistral-large-latest',
    ]);
}

/**
 * The broadcasting auth runs on the Mercure connection — the Browser
 * suite boots with BROADCAST_CONNECTION=null (no hub to talk to), so
 * the same in-process override as BroadcastingAuthTest pins a mercure
 * connection: the auth request mints its grant for the author alone,
 * and nothing here ever talks to a real hub.
 */
function configureMercureBroadcasting(): void
{
    config([
        'broadcasting.default' => 'mercure',
        'broadcasting.connections.mercure' => [
            'driver' => 'mercure',
            // Same host as the page the browser test visits: the
            // authorization cookie is host-only, so the hub's public URL
            // must share the application's host verbatim. Plain HTTP
            // forbids the __Secure- cookie prefix — the tests use a
            // prefix-less name.
            'url' => 'http://127.0.0.1/.well-known/mercure',
            'secret' => 'testing-mercure-jwt-secret-0123456789abcdef0123456789abcdef',
            'cookie_name' => 'mercure_access_token',
            'subscribe_expiration' => 5,
        ],
    ]);
}

/**
 * An acting owner with a workspace: the fixtures the Chatbot surfaces
 * need. Returns [user, workspace].
 */
function buildWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);

    app(WorkspaceContext::class)->set($workspace);

    return [$owner, $workspace];
}

/**
 * The section sleeps behind the instance flag by default (the owner's
 * decision of 2026-11-21): these tests exercise the pages themselves,
 * so they run with the flag on — the hidden-by-default behavior has its
 * own feature suite.
 */
beforeEach(function (): void {
    config()->set('taskr.features.chat', true);
});

it('renders the Chatbot pages and keeps the conversations author-only', function () {
    $owner = User::factory()->create();
    $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
    $other = User::factory()->create();
    $foreignThread = ChatThread::factory()->for($workspace)->for($other, 'user')->create(['title' => 'Not mine']);
    $myThread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create(['title' => 'Mon fil de réflexion']);

    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/chatbot');

    // The section lives in the navigation with its own entry.
    $page->assertSee('Chatbot')
        ->assertPresent('[data-test="chatbot-thread-row"]');

    // Only the requesting user's conversations appear — someone else's
    // is neither listed nor reachable.
    $page->assertSee('Mon fil de réflexion')
        ->assertDontSee('Not mine');

    // A deep link to another member's conversation fails exactly like a
    // missing one.
    visit('/chatbot/'.$foreignThread->id)->assertSee('404');

    // The conversation page renders the transcript for the author.
    $show = visit('/chatbot/'.$myThread->id);
    $show->assertSee('Mon fil de réflexion')
        ->assertPresent('[data-test="chatbot-transcript"]')
        ->assertPresent('[data-test="chatbot-back"]')
        ->assertNoSmoke();
});

it('shows an explicit disabled state without a provider and never subscribes', function () {
    $owner = User::factory()->create();
    $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
    $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create(['title' => 'Question ouverte']);

    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/chatbot');
    installChatEventSourceFake($page);

    // Without a configured provider the section says so explicitly and
    // the composer stays disabled.
    $page->assertSee('The assistant is not configured')
        ->assertPresent('[data-test="chatbot-disabled-banner"]')
        ->assertScript("document.querySelector('[data-test=\"chatbot-composer\"]').disabled", true);

    // Walking to a conversation mounts no subscription at all: nothing
    // to authorize, no EventSource ever constructed.
    $page->click('[data-test="chatbot-thread-row"]')
        ->waitForEvent('networkidle');

    $subscriptions = $page->script('(async () => { await new Promise((resolve) => setTimeout(resolve, 400)); return window.__chatFake.sources.length; })()');
    expect($subscriptions)->toBe(0);

    $page->assertSee('The assistant is not configured')
        ->assertNoSmoke();
});

it('streams the assistant answer in place without any page reload', function () {
    configureMercureBroadcasting();
    Queue::fake();

    [$owner, $workspace] = buildWorkspace();
    configureChatProvider($workspace);
    $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create(['title' => 'Résume le sprint']);
    $this->actingAs($owner);

    $page = visit('/chatbot');

    // Patch before the conversation mounts: the landing page carries no
    // subscription, so the fake is ready when the visit to the thread
    // mounts its own (one subscription, installed after ours).
    installChatEventSourceFake($page);
    $page->click('[data-test="chatbot-thread-row"]');

    $subscribed = $page->script('(async () => { const deadline = Date.now() + 5000; while (Date.now() < deadline) { if (window.__chatFake.sources.length > 0) return true; await new Promise((resolve) => setTimeout(resolve, 50)); } return false; })()');
    expect($subscribed)->toBe(true);

    // The subscription targets the thread's private Mercure topic on the
    // same-origin hub, behind one broadcasting auth request.
    $url = $page->script('window.__chatFake.sources[0].url');
    expect($url)->toContain('/.well-known/mercure')
        ->and(urldecode(parse_url($url, PHP_URL_QUERY)))
        ->toContain('https://laravel.alt/echo/channel/chat-threads.'.$thread->id);
    expect($page->script('window.__chatFake.authPosts'))->toBeGreaterThanOrEqual(1);

    // A reload probe — a full page navigation would lose it.
    $page->script('window.__chatProbe = 42');

    // Send the first message from the composer: a plain JSON post to the
    // existing 202 endpoint, no page reload.
    $page->fill('[data-test="chatbot-composer"]', 'Résume le sprint');
    $page->click('[data-test="chatbot-send"]');

    // The turn's 202 arrived and the two messages rendered locally.
    waitForChatPredicate($page, 'window.__chatFake.turnResponses.length > 0', 'the 202 response was recorded');
    $assistantId = $page->script('window.__chatFake.turnResponses[0].assistantMessageId');
    expect($assistantId)->not->toBe('');

    waitForChatPredicate($page, "document.querySelectorAll('[data-test=\"chatbot-message\"][data-role=\"user\"]').length > 0", 'the user bubble appeared');
    waitForChatPredicate($page, "document.querySelectorAll('[data-test=\"chatbot-message\"][data-role=\"assistant\"][data-status=\"pending\"]').length > 0", 'the pending assistant bubble appeared');
    $page->assertSee('The assistant is thinking…');

    // The stream: the fragments arrive live — the first one is visible
    // BEFORE the second, proving a progressive write, not a final paste.
    $page->script("window.__chatFake.sources[0].emit('assistant.chunk', { messageId: '{$assistantId}', delta: 'Le sprint ' });");
    waitForChatPredicate($page, "document.body.textContent.includes('Le sprint ')", 'the first fragment landed');
    $page->script("window.__chatFake.sources[0].emit('assistant.chunk', { messageId: '{$assistantId}', delta: 'est bien avancé.' });");
    waitForChatPredicate($page, "document.body.textContent.includes('Le sprint est bien avancé.')", 'the text grew with the second fragment');

    // A tool the model uses shows while it runs, then its readable
    // summary when the result settles.
    $page->script("window.__chatFake.sources[0].emit('tool.started', { messageId: '{$assistantId}', callId: 'call-1', name: 'search_knowledge' });");
    $page->assertSee('Using a tool…');
    $page->script("window.__chatFake.sources[0].emit('tool.result', { messageId: '{$assistantId}', callId: 'call-1', name: 'search_knowledge', ok: true, summary: 'Recherche dans la base de connaissances' });");
    waitForChatPredicate($page, "document.body.textContent.includes('Recherche dans la base de connaissances')", 'the settled tool summary landed');

    // The settle: the stopping of the pending state, the usage hint.
    $page->script("window.__chatFake.sources[0].emit('assistant.done', { messageId: '{$assistantId}', body: 'Le sprint est bien avancé.', usage: { total_tokens: 37 } });");
    waitForChatPredicate($page, "document.body.textContent.includes('≈ 37 tokens')", 'the usage hint landed');
    $page->assertPresent('[data-test="chatbot-usage"]');

    // No reload happened anywhere in the flow and the one subscription
    // stayed the same — a reload would have wiped and rebuilt the fake.
    expect($page->script('window.__chatProbe'))->toBe(42)
        ->and($page->script('window.__chatFake.installed'))->toBeTrue()
        ->and($page->script('window.__chatFake.sources.length'))->toBe(1);

    $page->assertNoSmoke();
});

it('surfaces a failed turn as an explicit banner without dead-ending the composer', function () {
    configureMercureBroadcasting();
    Queue::fake();

    [$owner, $workspace] = buildWorkspace();
    configureChatProvider($workspace);
    $thread = ChatThread::factory()->for($workspace)->for($owner, 'user')->create(['title' => 'Question']);
    $this->actingAs($owner);

    $page = visit('/chatbot');
    installChatEventSourceFake($page);
    $page->click('[data-test="chatbot-thread-row"]');

    $subscribed = $page->script('(async () => { const deadline = Date.now() + 5000; while (Date.now() < deadline) { if (window.__chatFake.sources.length > 0) return true; await new Promise((resolve) => setTimeout(resolve, 50)); } return false; })()');
    expect($subscribed)->toBe(true);

    // Send a message; the answer generation fails.
    $page->fill('[data-test="chatbot-composer"]', 'Une question');
    $page->click('[data-test="chatbot-send"]');

    waitForChatPredicate($page, 'window.__chatFake.turnResponses.length > 0', 'the 202 response was recorded');
    $assistantId = $page->script('window.__chatFake.turnResponses[0].assistantMessageId');

    // The turn errors: an explicit banner with the displayable line —
    // never a dead end, never a silent hang.
    $page->script("window.__chatFake.sources[0].emit('assistant.error', { messageId: '{$assistantId}', error: \"L'assistant n'a pas de fournisseur de chat configuré pour cet espace de travail.\" });");

    waitForChatPredicate($page, "document.querySelectorAll('[data-test=\"chatbot-message-error\"]').length > 0", 'the failure banner appeared');
    $page->assertSee('The response failed')
        ->assertSee("L'assistant n'a pas de fournisseur de chat configuré");

    // The flow can go on: typing a new message re-enables the send.
    $page->fill('[data-test="chatbot-composer"]', 'Deuxième');
    waitForChatPredicate($page, "document.querySelector('[data-test=\"chatbot-send\"]').disabled === false", 'the composer re-opened');
    $page->click('[data-test="chatbot-send"]');
    waitForChatPredicate($page, 'window.__chatFake.turnResponses.length > 1', 'the second turn started');

    expect($page->script('window.__chatFake.turnResponses[1].status'))->toBe('pending');

    $page->assertNoSmoke();
});
