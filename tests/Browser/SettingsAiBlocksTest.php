<?php

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Models\WorkspaceEmbeddingSetting;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Http;

/**
 * The /settings/embeddings screen's browser behaviour: two independent
 * save forms — the embeddings provider's own form and the chat
 * assistant's own form. Saving one block must never wipe the other
 * block's unsaved input, each block posts to its own endpoint, and both
 * blocks persist across a reload. The probes' endpoints are faked (the
 * suite runs the app in-process): a probe answering proves the button's
 * wiring, never the provider itself.
 */

/**
 * Waits (bounded) for a client-side JS predicate — used after each
 * Inertia save so the round-trip and React's render land before the
 * assertion.
 */
function waitForSettingsPredicate($page, string $expectation, string $subject): void
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
        throw new RuntimeException("AI settings condition not met: {$subject}");
    }
}

/**
 * An owner with a workspace whose embeddings provider is already
 * configured, together with a first chat configuration (the chat block
 * rides the same saved row): the fixtures the settings screen needs to
 * render both forms for an owner. Without a stored chat_model the
 * block's pre-existing empty state hides the form entirely — the save
 * wiring this spec covers lives in the rendered block.
 */
function buildSettingsWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
    $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'embeddings-key',
        'model' => 'mistral-embed',
        'dimensions' => 1024,
        'send_dimensions' => false,
        'chat_base_url' => 'https://seeded.test/v1',
        'chat_api_key' => 'seeded-chat-key',
        'chat_model' => 'seeded-chat-model',
    ]);

    app(WorkspaceContext::class)->set($workspace);

    return [$owner, $workspace, $setting];
}

/**
 * The embeddings save saves the provider through a real probe; the chat
 * save too. Fake every outgoing HTTP call so both flows complete
 * without a real provider, with the shapes each probe reads back.
 */
function fakeSettingsProviders(): void
{
    config()->set('knowledge.embeddings', [
        'base_url' => null,
        'api_key' => null,
        'model' => null,
        'dimensions' => 1024,
    ]);

    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'pong']]],
        ]),
        '*/embeddings' => Http::response([
            'data' => [['embedding' => array_fill(0, 1024, 0.5)]],
        ]),
    ]);
}

it('saves the chat block and the embeddings block independently as the owner', function () {
    fakeSettingsProviders();
    [$owner, $workspace] = buildSettingsWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/embeddings');

    $page->assertPresent('[data-test="embeddings-form"]')
        ->assertPresent('[data-test="chat-settings"]');

    // Two distinct models, one per block — typed, not yet saved.
    $page->fill('[data-test="embeddings-model"]', 'embed-model-x');
    $page->fill('[data-test="chat-base-url"]', 'https://chat.test/v1');
    $page->fill('[data-test="chat-model"]', 'chat-model-y');

    // Save the chat block: it persists on its own route, and the
    // embeddings model typed a moment ago stays in its field.
    $page->click('[data-test="chat-save"]');
    waitForSettingsPredicate($page, "document.body.textContent.includes('AI settings saved.')", 'the chat save flash banner');

    $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->first();
    expect($setting->chat_model)->toBe('chat-model-y')
        ->and($setting->model)->toBe('mistral-embed');
    expect($page->script("document.querySelector('[data-test=\"embeddings-model\"]').value"))->toBe('embed-model-x');

    // Save the embeddings block too: the chat block keeps its saved
    // values, nothing is crossed out.
    $page->click('[data-test="embeddings-save"]');
    waitForSettingsPredicate($page, "document.body.textContent.includes('Embeddings provider saved for this workspace.')", 'the embeddings save flash banner');

    $setting->refresh();
    expect($setting->model)->toBe('embed-model-x')
        ->and($setting->chat_model)->toBe('chat-model-y');

    // A full reload: both models coexist and display in their own block.
    $reloaded = visit('/settings/embeddings');
    expect($reloaded->script("document.querySelector('[data-test=\"embeddings-model\"]').value"))->toBe('embed-model-x')
        ->and($reloaded->script("document.querySelector('[data-test=\"chat-model\"]').value"))->toBe('chat-model-y')
        ->and($reloaded->script("document.querySelector('[data-test=\"chat-base-url\"]').value"))->toBe('https://chat.test/v1');

    // Both probes answer through the fake — the wiring is live.
    $reloaded->click('[data-test="embeddings-test"]');
    waitForSettingsPredicate($reloaded, "document.querySelector('[data-test=\"embeddings-test-result\"]') !== null", 'the embeddings probe result');
    $reloaded->click('[data-test="chat-test"]');
    waitForSettingsPredicate($reloaded, "document.querySelector('[data-test=\"chat-test-result\"]') !== null", 'the chat probe result');

    $reloaded->assertNoSmoke();
});

it('loads the provider models into a menu and the pick fills the model field', function () {
    fakeSettingsProviders();
    Http::fake([
        'https://embeddings.test/v1/models' => Http::response([
            'object' => 'list',
            // Unsorted on purpose: the menu must present a sorted list.
            'data' => [['id' => 'embed-model-b'], ['id' => 'embed-model-a']],
        ]),
        'https://chat.test/v1/models' => Http::response([
            'object' => 'list',
            // Mixed shapes: only string ids survive the parse.
            'data' => [['id' => 'chat-model-a'], ['id' => 42], 'chat-model-b'],
        ]),
    ]);
    [$owner, $workspace] = buildSettingsWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/embeddings');

    // The embeddings block loads its own provider's models: the menu
    // lists them (sorted), a pick fills the editable model field.
    $page->click('[data-test="embeddings-models-load"]');
    waitForSettingsPredicate($page, "document.querySelector('[data-test=\"embeddings-models-select\"]') !== null", 'the embeddings models menu');
    $page->click('[data-test="embeddings-models-select"]');
    $page->click('[role="option"]:has-text("embed-model-a")');
    waitForSettingsPredicate($page, "document.querySelector('[data-test=\"embeddings-model\"]').value === 'embed-model-a'", 'the embeddings model field');

    // The chat block loads ITS list from ITS endpoint — a distinct
    // provider, a distinct menu, nothing crossed between the blocks.
    $page->fill('[data-test="chat-base-url"]', 'https://chat.test/v1');
    $page->fill('[data-test="chat-api-key"]', 'chat-secret-key');
    $page->click('[data-test="chat-models-load"]');
    waitForSettingsPredicate($page, "document.querySelector('[data-test=\"chat-models-select\"]') !== null", 'the chat models menu');
    $page->click('[data-test="chat-models-select"]');
    $page->click('[role="option"]:has-text("chat-model-a")');
    waitForSettingsPredicate($page, "document.querySelector('[data-test=\"chat-model\"]').value === 'chat-model-a'", 'the chat model field');

    // Both picks persist through a save of each block and a reload.
    $page->click('[data-test="chat-save"]');
    waitForSettingsPredicate($page, "document.body.textContent.includes('AI settings saved.')", 'the chat save flash banner');

    $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->first();
    expect($setting->chat_model)->toBe('chat-model-a')
        ->and($setting->chat_base_url)->toBe('https://chat.test/v1');

    $page->click('[data-test="embeddings-save"]');
    waitForSettingsPredicate($page, "document.body.textContent.includes('Embeddings provider saved for this workspace.')", 'the embeddings save flash banner');

    $setting->refresh();
    expect($setting->model)->toBe('embed-model-a')
        ->and($setting->chat_model)->toBe('chat-model-a');

    $reloaded = visit('/settings/embeddings');
    expect($reloaded->script("document.querySelector('[data-test=\"embeddings-model\"]').value"))->toBe('embed-model-a')
        ->and($reloaded->script("document.querySelector('[data-test=\"chat-model\"]').value"))->toBe('chat-model-a');

    $reloaded->assertNoSmoke();
});

it('keeps the model field usable when the provider refuses the model listing', function () {
    fakeSettingsProviders();
    Http::fake([
        'https://chat.test/v1/models' => Http::response(['message' => 'Unauthorized'], 401),
    ]);
    [$owner, $workspace] = buildSettingsWorkspace();
    $this->actingAs($owner);

    $page = visit('/settings/embeddings');

    $page->fill('[data-test="chat-base-url"]', 'https://chat.test/v1');
    $page->fill('[data-test="chat-api-key"]', 'chat-secret-key');
    $page->click('[data-test="chat-models-load"]');

    waitForSettingsPredicate($page, "document.querySelector('[data-test=\"chat-models-error\"]') !== null", 'the chat models error message');
    $page->assertPresent('[data-test="chat-model"]');
    $page->fill('[data-test="chat-model"]', 'manually-typed-model');
    expect($page->script("document.querySelector('[data-test=\"chat-model\"]').value"))->toBe('manually-typed-model');

    $page->assertNoSmoke();
});

it('lets the owner configure the chat for the first time from the empty state', function () {
    fakeSettingsProviders();

    // The real pristine case: any workspace created before the chat save
    // worked has no chat settings at all — only the embeddings provider
    // on the row. The block's empty state must never mask its form: the
    // settings page exists to make this very configuration.
    $owner = User::factory()->create();
    $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
    WorkspaceEmbeddingSetting::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->id,
        'base_url' => 'https://embeddings.test/v1',
        'api_key' => 'embeddings-key',
        'model' => 'mistral-embed',
        'dimensions' => 1024,
        'send_dimensions' => false,
    ]);

    app(WorkspaceContext::class)->set($workspace);
    $this->actingAs($owner);

    $page = visit('/settings/embeddings');

    // The disabled-chat warning shows AND the chat form stays actionable.
    $page->assertPresent('[data-test="chat-empty-state"]')
        ->assertPresent('[data-test="chat-base-url"]')
        ->assertPresent('[data-test="chat-save"]');

    $page->fill('[data-test="chat-base-url"]', 'https://first-config.test/v1');
    $page->fill('[data-test="chat-api-key"]', 'first-key');
    $page->fill('[data-test="chat-model"]', 'first-chat-model');

    // First save: the flash confirms, the row carries the chat settings.
    $page->click('[data-test="chat-save"]');
    waitForSettingsPredicate($page, "document.body.textContent.includes('AI settings saved.')", 'the first chat save flash banner');

    $setting = WorkspaceEmbeddingSetting::withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->first();
    expect($setting->chat_model)->toBe('first-chat-model')
        ->and($setting->chat_base_url)->toBe('https://first-config.test/v1');

    // Reload: the effective summary reports the pair (never the key) and
    // the empty-state warning is gone.
    $reloaded = visit('/settings/embeddings');
    expect($reloaded->script("document.querySelector('[data-test=\"chat-model\"]').value"))->toBe('first-chat-model');
    $reloaded->assertSee('first-chat-model')
        ->assertSee('https://first-config.test/v1')
        ->assertNoSmoke();
});
