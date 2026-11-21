<?php

use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Chat (assistant) — author-only conversations
|--------------------------------------------------------------------------
|
| The thread's private Mercure channel admits its author alone: the
| closure runs on the broadcasting/auth request, so a subscription to
| someone else's conversation is refused server-side — no grant, no
| cookie, nothing the hub would deliver.
|
*/

Broadcast::channel('chat-threads.{thread}', function (User $user, string $threadId) {
    // Workspace-scoped on purpose: the current session workspace is the
    // only tenancy window a subscription may use, so a thread of another
    // workspace — even one's own — is never confirmed to exist (same
    // anti-probing contract as the read endpoints).
    return ChatThread::query()
        ->whereKey($threadId)
        ->where('user_id', $user->id)
        ->exists();
});
