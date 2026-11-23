import { Button } from '@/components/ui/button';
import { MarkdownView } from '@/components/ui/markdown-view';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, MessageSquare, Send } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
    subscribeToChatThread,
    type ChatSubscription,
} from '@/lib/chat-events';

type ToolCallView = { callId: string; name: string; ok: boolean | null; summary: string | null };

type MessageView = {
    id: string;
    threadId: string;
    role: 'user' | 'assistant';
    body: string;
    status: 'pending' | 'complete' | 'failed' | null;
    error: string | null;
    toolCalls: ToolCallView[];
    usage: Record<string, number> | null;
    createdAt: string | null;
};

type ThreadItem = {
    id: string;
    title: string | null;
    createdAt: string | null;
};

type PageProps = {
    thread: ThreadItem;
    messages: Array<{
        id: string;
        threadId: string;
        role: 'user' | 'assistant';
        body: string;
        status: 'pending' | 'complete' | 'failed' | null;
        error: string | null;
        toolCalls: Array<{ callId: string; name: string; ok: boolean | null; summary: string | null }> | null;
        usage: Record<string, number> | null;
        createdAt: string | null;
    }>;
    /** Whether a chat provider is configured for the workspace. */
    chatEnabled: boolean;
};

/**
 * One conversation: the transcript, the composer, and the assistant's
 * reply streaming in live. The composer posts JSON to the conversation
 * (no page reload); the turn's events — fragments, tool states, the
 * settle — arrive on the thread's private channel and write into the
 * transcript in place.
 */
export default function Show() {
    const { thread, messages: initialMessages, chatEnabled } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const [messages, setMessages] = useState<MessageView[]>(() =>
        initialMessages.map((message) => ({
            ...message,
            toolCalls: message.toolCalls ?? [],
        })),
    );
    const [connected, setConnected] = useState(false);
    const [content, setContent] = useState('');
    const [sending, setSending] = useState(false);
    const [sendFailed, setSendFailed] = useState(false);

    const threadTitle =
        thread.title === null || thread.title === '' ? t('chatbotPage.untitledThread') : thread.title;

    useEffect(() => {
        if (!chatEnabled) {
            return;
        }

        let active = true;
        let subscription: ChatSubscription | null = null;

        const streamingThread = thread;

        void subscribeToChatThread(streamingThread.id, {
            onConnected: (connectedNow) => {
                if (active) {
                    setConnected(connectedNow);
                }
            },
            onChunk: (messageId, delta) => {
                setMessages((current) =>
                    current.map((message) =>
                        message.id === messageId ? { ...message, body: message.body + delta } : message,
                    ),
                );
            },
            onToolStarted: (messageId, callId, name) => {
                setMessages((current) =>
                    current.map((message) =>
                        message.id === messageId && !message.toolCalls.some((call) => call.callId === callId)
                            ? {
                                  ...message,
                                  toolCalls: [...message.toolCalls, { callId, name, ok: null, summary: null }],
                              }
                            : message,
                    ),
                );
            },
            onToolResult: (messageId, callId, _name, ok, summary) => {
                setMessages((current) =>
                    current.map((message) =>
                        message.id === messageId
                            ? {
                                  ...message,
                                  toolCalls: message.toolCalls.map((call) =>
                                      call.callId === callId ? { ...call, ok, summary } : call,
                                  ),
                              }
                            : message,
                    ),
                );
            },
            onDone: (messageId, body, usage) => {
                setMessages((current) =>
                    current.map((message) =>
                        message.id === messageId ? { ...message, body, status: 'complete', usage } : message,
                    ),
                );
            },
            onError: (messageId, error) => {
                setMessages((current) =>
                    current.map((message) =>
                        message.id === messageId ? { ...message, status: 'failed', error } : message,
                    ),
                );
            },
        }).then((opened) => {
            if (active) {
                subscription = opened;
            } else {
                opened.close();
            }
        });

        return () => {
            active = false;
            subscription?.close();
        };
    }, [chatEnabled, thread]);

    const submitMessage = (event: FormEvent) => {
        event.preventDefault();

        if (content.trim() === '' || sending) {
            return;
        }

        setSending(true);
        setSendFailed(false);

        // Inertia's CSRF handling covers its own visits only: the token
        // rides the JSON post's header manually (as on the settings page).
        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];
        const contentValue = content;

        void fetch(route('chat.threads.messages.store', thread.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({ content: contentValue }),
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((turn: { userMessageId: string; assistantMessageId: string }) => {
                setContent('');
                setMessages((current) => [
                    ...current,
                    { id: turn.userMessageId, threadId: thread.id, role: 'user', body: contentValue, status: 'complete', error: null, toolCalls: [], usage: null, createdAt: null },
                    { id: turn.assistantMessageId, threadId: thread.id, role: 'assistant', body: '', status: 'pending', error: null, toolCalls: [], usage: null, createdAt: null },
                ]);
            })
            .catch(() => setSendFailed(true))
            .finally(() => setSending(false));
    };

    const transcriptEnd = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        transcriptEnd.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    const pending = messages.some((message) => message.status === 'pending');

    return (
        <AppLayout
            title={threadTitle}
            leading={
                <Link
                    href={route('chatbot.index')}
                    data-test="chatbot-back"
                    className="flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft aria-hidden className="size-4" />
                    <span className="hidden sm:inline">{t('chatbotPage.backToThreads')}</span>
                </Link>
            }
        >
            <Head title={`${t('chatbotPage.title')} — ${threadTitle}`} />

            {!chatEnabled ? (
                <div
                    className="mb-6 rounded-xl border border-border bg-muted/50 p-4"
                    data-test="chatbot-disabled-banner"
                >
                    <p className="text-sm font-medium">{t('chatbotPage.disabledTitle')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">{t('chatbotPage.disabledDescription')}</p>
                </div>
            ) : null}

            <div
                className="flex flex-col gap-4"
                data-test="chatbot-transcript"
                aria-label={t('chatbotPage.transcriptAria')}
            >
                {messages.map((message) => (
                    <div
                        key={message.id}
                        data-test="chatbot-message"
                        data-role={message.role}
                        data-status={message.status ?? undefined}
                        className={
                            message.role === 'user'
                                ? 'ml-auto max-w-[85%] rounded-2xl rounded-br-md bg-primary px-4 py-2.5 text-sm text-primary-foreground sm:max-w-[70%]'
                                : 'mr-auto max-w-[85%] rounded-2xl rounded-bl-md border bg-card px-4 py-2.5 text-sm sm:max-w-[80%]'
                        }
                    >
                        {message.body === '' ? null : (
                            <MarkdownView
                                value={message.body}
                                className={message.role === 'user' ? '[&_a]:text-inherit [&_code]:bg-background/40' : undefined}
                            />
                        )}
                        {message.status === 'pending' ? (
                            <p className="text-[13px] text-muted-foreground" data-test="chatbot-thinking">
                                {t('chatbotPage.thinking')}
                            </p>
                        ) : null}
                        {message.toolCalls.length > 0 ? (
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {message.toolCalls.map((call) => (
                                    <span
                                        key={call.callId}
                                        data-test="chatbot-tool-pill"
                                        data-ok={call.ok === null ? 'running' : call.ok ? 'true' : 'false'}
                                        className="inline-flex items-center gap-1 rounded-full border bg-muted/60 px-2 py-0.5 text-xs text-muted-foreground"
                                    >
                                        <MessageSquare aria-hidden className="size-3" />
                                        {call.summary ?? t('chatbotPage.toolRunning')}
                                    </span>
                                ))}
                            </div>
                        ) : null}
                        {message.status === 'failed' && message.error !== null ? (
                            <div
                                data-test="chatbot-message-error"
                                role="alert"
                                className="mt-2 rounded-lg border border-destructive/30 bg-destructive/5 p-2"
                            >
                                <p className="text-[13px] font-medium text-destructive">
                                    {t('chatbotPage.errorBannerTitle')}
                                </p>
                                <p className="mt-0.5 text-[13px] text-muted-foreground">{message.error}</p>
                            </div>
                        ) : null}
                        {message.status === 'complete' &&
                        message.usage !== null &&
                        typeof message.usage.total_tokens === 'number' ? (
                            <p className="mt-2 text-xs text-muted-foreground" data-test="chatbot-usage">
                                {t('chatbotPage.usage', { tokens: message.usage.total_tokens })}
                            </p>
                        ) : null}
                    </div>
                ))}
                <div ref={transcriptEnd} aria-hidden />
            </div>

            <form onSubmit={submitMessage} className="mt-6" data-test="chatbot-turn-composer">
                <Textarea
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                    placeholder={t('chatbotPage.composerPlaceholder')}
                    disabled={!chatEnabled || sending || pending}
                    rows={3}
                    className="mb-3 resize-none"
                    data-test="chatbot-composer"
                    aria-label={t('chatbotPage.composerLabel')}
                />
                {sendFailed ? (
                    <p className="mb-2 text-sm text-destructive" data-test="chatbot-send-failed">
                        {t('chatbotPage.sendFailed')}
                    </p>
                ) : null}
                {!connected && chatEnabled ? (
                    <p className="mb-2 text-xs text-muted-foreground" data-test="chatbot-connection">
                        {t('chatbotPage.reconnecting')}
                    </p>
                ) : null}
                <div className="flex justify-end">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={!chatEnabled || sending || pending || content.trim() === ''}
                        data-test="chatbot-send"
                    >
                        <Send aria-hidden />
                        {sending ? t('common.loading') : t('chatbotPage.send')}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
