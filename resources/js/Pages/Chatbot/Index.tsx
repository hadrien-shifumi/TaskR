import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { MessageSquare, Send } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatRelativeDate } from '@/lib/format-date';

type ThreadItem = {
    id: string;
    title: string | null;
    createdAt: string | null;
    updatedAt: string | null;
};

type PageProps = {
    threads: ThreadItem[];
    /** Whether a chat provider is configured for the workspace. */
    chatEnabled: boolean;
};

/**
 * The Chatbot section's landing page: the conversations of the current
 * user, most recently active first, and the composer that opens a new
 * one. The send is a plain JSON post to the chat endpoint (no page
 * reload) — a 202 accepts the turn, then the page visits the new
 * conversation where the answer streams in.
 */
export default function Index() {
    const { threads, chatEnabled } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [content, setContent] = useState('');
    const [starting, setStarting] = useState(false);
    const [startFailed, setStartFailed] = useState(false);

    const startConversation = (event: FormEvent) => {
        event.preventDefault();

        if (!chatEnabled || content.trim() === '' || starting) {
            return;
        }

        setStarting(true);
        setStartFailed(false);

        // The composer posts JSON to the existing chat endpoint — the
        // 202 carries the new thread's ids; Inertia's visit goes to it
        // without a full reload (the answer streams there). Inertia's
        // CSRF handling only covers its own visits: the cookie's token
        // rides the header manually.
        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

        void fetch(route('chat.threads.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({ content }),
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((turn: { threadId: string }) => {
                router.visit(route('chatbot.show', turn.threadId));
            })
            .catch(() => setStartFailed(true))
            .finally(() => setStarting(false));
    };

    return (
        <AppLayout
            title={t('chatbotPage.title')}
            subtitle={t('chatbotPage.subtitle')}
        >
            <Head title={t('chatbotPage.title')} />

            {!chatEnabled ? (
                <div
                    className="mb-6 rounded-xl border border-border bg-muted/50 p-4"
                    data-test="chatbot-disabled-banner"
                >
                    <p className="text-sm font-medium">{t('chatbotPage.disabledTitle')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t('chatbotPage.disabledDescription')}
                    </p>
                </div>
            ) : null}

            <form onSubmit={startConversation}>
                <Textarea
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                    placeholder={t('chatbotPage.composerPlaceholder')}
                    disabled={!chatEnabled || starting}
                    rows={3}
                    className="mb-3 resize-none"
                    data-test="chatbot-composer"
                    aria-label={t('chatbotPage.composerLabel')}
                />
                {startFailed ? (
                    <p className="mb-2 text-sm text-destructive" data-test="chatbot-send-failed">
                        {t('chatbotPage.sendFailed')}
                    </p>
                ) : null}
                <div className="mb-8 flex justify-end">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={!chatEnabled || starting || content.trim() === ''}
                        data-test="chatbot-compose-start"
                    >
                        <Send aria-hidden />
                        {starting ? t('common.loading') : t('chatbotPage.start')}
                    </Button>
                </div>
            </form>

            {threads.length === 0 ? (
                <div className="rounded-xl border bg-card p-8 text-center" data-test="chatbot-threads-empty">
                    <p className="text-sm font-medium">{t('chatbotPage.emptyThreads')}</p>
                    <p className="mt-1 text-sm text-muted-foreground">{t('chatbotPage.emptyThreadsHint')}</p>
                </div>
            ) : (
                <ul
                    className="divide-y rounded-xl border bg-card"
                    data-test="chatbot-threads-list"
                    aria-label={t('chatbotPage.threadsListLabel')}
                >
                    {threads.map((thread) => (
                        <li key={thread.id}>
                            <Link
                                href={route('chatbot.show', thread.id)}
                                data-test="chatbot-thread-row"
                                className="flex items-center gap-3 p-4 transition-colors hover:bg-secondary/60"
                            >
                                <MessageSquare aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-medium">
                                        {thread.title === null || thread.title === ''
                                            ? t('chatbotPage.untitledThread')
                                            : thread.title}
                                    </span>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {formatRelativeDate(thread.updatedAt)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
