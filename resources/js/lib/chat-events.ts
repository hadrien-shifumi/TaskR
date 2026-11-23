/**
 * The client side of the chat V1 streaming — no extra dependency. The
 * conversation's fragments live on its private Mercure channel: a small
 * fetch first asks the Laravel broadcasting auth endpoint for the
 * subscription (the server grants the author alone and answers with the
 * hub's authorization cookie), then a native `EventSource` listens on
 * the same-origin hub and routes every broadcast envelope — event name
 * + payload — to its handler.
 */

export type ChatStreamHandlers = {
    onChunk?: (messageId: string, delta: string) => void;
    onToolStarted?: (messageId: string, callId: string, name: string) => void;
    onToolResult?: (messageId: string, callId: string, name: string, ok: boolean, summary: string) => void;
    onDone?: (messageId: string, body: string, usage: Record<string, number> | null) => void;
    onError?: (messageId: string, error: string) => void;
    /** Connection state of the live stream (open ⇄ restablishing). */
    onConnected?: (connected: boolean) => void;
};

export type ChatSubscription = { close: () => void };

/** The broadcaster's topic namespace: prefix + `channel/` + channel name. */
export const CHAT_TOPIC_PREFIX = 'https://laravel.alt/echo/channel/';

export function chatThreadChannelName(threadId: string): string {
    return `chat-threads.${threadId}`;
}

/** The exact Mercure topic the hub publishes book fragments on. */
export function chatThreadTopic(threadId: string): string {
    return CHAT_TOPIC_PREFIX + encodeURIComponent(chatThreadChannelName(threadId));
}

/** The hub URL path on the same origin — no environment variable needed. */
export const CHAT_HUB_PATH = '/.well-known/mercure';

/**
 * The broadcasting auth request: `channel_names` (the framework's own
 * contract) with the CSRF header Inertia does not manage. One granted
 * entry for the author; a refusal never confirms a thread's existence.
 */
export async function authorizeChatChannel(channelName: string): Promise<boolean> {
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

    try {
        const response = await fetch('/broadcasting/auth', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({ channel_names: [channelName] }),
        });

        if (!response.ok) {
            return false;
        }

        const payload = (await response.json()) as {
            channel_names?: Array<{ name: string; denied?: boolean }>;
        };

        const entry = payload.channel_names?.find((candidate) => candidate.name === channelName);

        return entry !== undefined && entry.denied !== true;
    } catch {
        return false;
    }
}

/**
 * Subscribe to one conversation's private channel: broadcast auth, then
 * the native EventSource on the thread topic. Mercure carries Laravel's
 * broadcast envelope on the default `message` SSE event — the event
 * name and the payload travel inside the JSON `data`. The returned
 * subscription closes the stream for good (unmount, page change); a
 * dropped connection reconnects natively (EventSource retry), surfaced
 * only through `onConnected(true|false)`.
 */
export async function subscribeToChatThread(
    threadId: string,
    handlers: ChatStreamHandlers,
): Promise<ChatSubscription> {
    const channelName = chatThreadChannelName(threadId);

    const granted = await authorizeChatChannel(channelName);

    if (!granted) {
        return { close: () => {} };
    }

    const source = new EventSource(`${CHAT_HUB_PATH}?topic=${encodeURIComponent(chatThreadTopic(threadId))}`);

    source.onopen = () => handlers.onConnected?.(true);
    source.onerror = () => handlers.onConnected?.(false);
    source.onmessage = (event: MessageEvent<string>) => {
        let envelope: { event?: string; payload?: Record<string, unknown> };

        try {
            envelope = JSON.parse(event.data);
        } catch {
            return;
        }

        dispatchChatEnvelope(envelope, handlers);
    };

    return {
        close: () => {
            source.onopen = null;
            source.onerror = null;
            source.onmessage = null;
            source.close();
            handlers.onConnected?.(false);
        },
    };
}

type ChatEnvelopeDispatcher = (
    envelope: { event?: string; payload?: Record<string, unknown> },
    handlers: ChatStreamHandlers,
) => void;

/** Routes one broadcast envelope to its handler by event name. */
export const dispatchChatEnvelope: ChatEnvelopeDispatcher = (envelope, handlers) => {
    const payload = envelope.payload ?? {};
    const messageId = typeof payload.messageId === 'string' ? payload.messageId : '';

    switch (envelope.event) {
        case 'assistant.chunk':
            if (typeof payload.delta === 'string') {
                handlers.onChunk?.(messageId, payload.delta);
            }
            break;
        case 'tool.started':
            if (typeof payload.callId === 'string' && typeof payload.name === 'string') {
                handlers.onToolStarted?.(messageId, payload.callId, payload.name);
            }
            break;
        case 'tool.result':
            if (
                typeof payload.callId === 'string' &&
                typeof payload.name === 'string' &&
                typeof payload.ok === 'boolean' &&
                typeof payload.summary === 'string'
            ) {
                handlers.onToolResult?.(messageId, payload.callId, payload.name, payload.ok, payload.summary);
            }
            break;
        case 'assistant.done':
            if (typeof payload.body === 'string') {
                handlers.onDone?.(
                    messageId,
                    payload.body,
                    typeof payload.usage === 'object' && payload.usage !== null
                        ? (payload.usage as Record<string, number>)
                        : null,
                );
            }
            break;
        case 'assistant.error':
            if (typeof payload.error === 'string') {
                handlers.onError?.(messageId, payload.error);
            }
            break;
        default:
            // An unknown broadcast is ignored, never fatal.
            break;
    }
};
