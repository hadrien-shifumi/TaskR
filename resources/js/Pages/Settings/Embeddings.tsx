import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';

type EmbeddingsSettings = {
    baseUrl: string;
    model: string;
    dimensions: number;
    sendDimensions: boolean;
    hasApiKey: boolean;
    // The chat block rides the same saved row — its own fields, never a key.
    chatBaseUrl: string | null;
    chatModel: string | null;
    chatHasApiKey: boolean;
};

type ChatProviderSummary = {
    source: 'workspace' | 'fallback' | 'none';
    baseUrl: string | null;
    model: string | null;
    hasApiKey: boolean;
};

type ActiveProvider = {
    source: 'workspace' | 'global' | 'none';
    baseUrl: string | null;
    model: string | null;
    dimensions: number | null;
    hasApiKey: boolean;
};

type PageProps = {
    settings: EmbeddingsSettings | null;
    activeProvider: ActiveProvider;
    /** The conversation assistant's effective pair (never the key itself). */
    chatProvider: ChatProviderSummary | null;
    requiredDimensions: number;
};

type Preset = {
    value: 'custom' | 'mistral' | 'openai' | 'ollama';
    labelKey: string;
    baseUrl: string;
    model: string;
    /** Native dimension of the preset model, when known. */
    dimensions: number | null;
    /** Placeholder key local providers expect. */
    apiKey?: string;
};

type ModelsLoad =
    | { status: 'idle' }
    | { status: 'pending' }
    | { status: 'ok'; models: string[] }
    | { status: 'empty' }
    | { status: 'failed'; message: string | null };

/** The first validation message a structured 422 refusal carries, if any. */
const firstErrorMessage = (payload: unknown): string | null => {
    const errors = (payload as { errors?: Record<string, string[]> } | null)?.errors;
    const first = errors ? Object.values(errors).flat()[0] : undefined;

    return typeof first === 'string' && first !== '' ? first : null;
};

/** One answered `{ id }` (or a bare string) → the model id, or nothing. */
const asModelId = (item: unknown): string | null => {
    if (typeof item === 'string') {
        return item !== '' ? item : null;
    }

    const id = (item as { id?: unknown } | null)?.id;

    return typeof id === 'string' && id !== '' ? id : null;
};

/** The provider's answer parsed down to its usable model ids. */
const modelIdsFrom = (payload: { models?: unknown } | null): string[] =>
    (Array.isArray(payload?.models) ? (payload!.models as unknown[]) : [])
        .map(asModelId)
        .filter((id): id is string => id !== null);

const PRESETS: Preset[] = [
    {
        value: 'mistral',
        labelKey: 'embeddingsPage.presetMistral',
        baseUrl: 'https://api.mistral.ai/v1',
        model: 'mistral-embed',
        dimensions: 1024,
    },
    {
        value: 'openai',
        labelKey: 'embeddingsPage.presetOpenAI',
        baseUrl: 'https://api.openai.com/v1',
        model: 'text-embedding-3-small',
        dimensions: 1536,
    },
    {
        value: 'ollama',
        labelKey: 'embeddingsPage.presetOllama',
        baseUrl: 'http://localhost:11434/v1',
        model: 'nomic-embed-text',
        dimensions: 768,
        apiKey: 'ollama',
    },
    {
        value: 'custom',
        labelKey: 'embeddingsPage.presetCustom',
        baseUrl: '',
        model: '',
        dimensions: null,
    },
];

const CHAT_PRESETS = [
    {
        value: 'mistral',
        labelKey: 'chatPage.presetMistral',
        baseUrl: 'https://api.mistral.ai/v1',
        model: 'mistral-large-latest',
    },
    {
        value: 'openai',
        labelKey: 'chatPage.presetOpenAI',
        baseUrl: 'https://api.openai.com/v1',
        model: 'gpt-4o-mini',
    },
    {
        value: 'custom',
        labelKey: 'chatPage.presetCustom',
        baseUrl: '',
        model: '',
    },
];

export default function Embeddings() {
    const { settings, activeProvider, chatProvider, requiredDimensions } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const isOwner = workspace?.role === 'owner';

    // Two independent forms: the embeddings provider's own fields, and
    // the chat assistant's own fields. Each posts to its own route with
    // its own success reset — saving one block never wipes the other
    // block's unsaved typing.
    const embeddingsForm = useForm({
        base_url: settings?.baseUrl ?? '',
        api_key: '',
        model: settings?.model ?? '',
        dimensions: settings?.sendDimensions ? (settings?.dimensions ?? null) : ('' as number | ''),
    });

    const chatForm = useForm({
        // The chat's own fields — the endpoint and the key are editable, the
        // pair fallback (both left empty) reuses the embeddings pair.
        chat_base_url: settings?.chatBaseUrl ?? '',
        chat_api_key: '',
        chat_model: settings?.chatModel ?? '',
    });

    const [preset, setPreset] = useState<string>(settings ? 'custom' : '');
    const [chatPreset, setChatPreset] = useState<string>(settings ? 'custom' : '');
    const [probeState, setProbeState] =
        useState<
            { status: 'idle' } | { status: 'pending' } | { status: 'ok'; dimensions: number; latencyMs: number } | { status: 'failed' }
        >({ status: 'idle' });
    const [chatProbeState, setChatProbeState] =
        useState<{ status: 'idle' } | { status: 'pending' } | { status: 'ok'; content: string; latencyMs: number } | { status: 'failed' }>({ status: 'idle' });
    const [embeddingsModels, setEmbeddingsModels] = useState<ModelsLoad>({ status: 'idle' });
    const [embeddingsModelsChoice, setEmbeddingsModelsChoice] = useState('');
    const [chatModels, setChatModels] = useState<ModelsLoad>({ status: 'idle' });
    const [chatModelsChoice, setChatModelsChoice] = useState('');

    // The models menus are fed by the backend: the same candidate-pair
    // resolution as the save flow (a blank key field reuses the stored
    // one), the provider's OpenAI-answer parsed only for `{ data: [{ id }] }`.
    const loadEmbeddingsModels = () => {
        setEmbeddingsModels({ status: 'pending' });

        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

        fetch(route('settings.embeddings.models'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({
                base_url: embeddingsForm.data.base_url,
                api_key: embeddingsForm.data.api_key,
            }),
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => null);

                if (!response.ok) {
                    const message = firstErrorMessage(payload) ?? t('embeddingsPage.modelsLoadFailed');
                    setEmbeddingsModels({ status: 'failed', message });

                    return;
                }

                const models = modelIdsFrom(payload);

                setEmbeddingsModels(models.length > 0 ? { status: 'ok', models } : { status: 'empty' });
            })
            .catch(() => setEmbeddingsModels({ status: 'failed', message: null }));
    };

    const loadChatModels = () => {
        setChatModels({ status: 'pending' });

        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

        fetch(route('settings.embeddings.chat-models'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({
                chat_base_url: chatForm.data.chat_base_url,
                chat_api_key: chatForm.data.chat_api_key,
            }),
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => null);

                if (!response.ok) {
                    const message = firstErrorMessage(payload) ?? t('chatPage.modelsLoadFailed');
                    setChatModels({ status: 'failed', message });

                    return;
                }

                const models = modelIdsFrom(payload);

                setChatModels(models.length > 0 ? { status: 'ok', models } : { status: 'empty' });
            })
            .catch(() => setChatModels({ status: 'failed', message: null }));
    };

    // Magic value: an empty write-only key field keeps the stored key.
    const applyPreset = (value: string) => {
        const next = PRESETS.find((candidate) => candidate.value === value);

        setPreset(value);
        setProbeState({ status: 'idle' });

        if (!next) {
            return;
        }

        embeddingsForm.setData((data) => ({
            ...data,
            base_url: next.baseUrl,
            model: next.model,
            api_key: next.apiKey ?? data.api_key,
        }));
    };

    // The chat presets never touch the embeddings ones: no dimension logic —
    // the conversational model has none (the only point separating the
    // chat's presets from the embeddings ones).
    const applyChatPreset = (value: string) => {
        const next = CHAT_PRESETS.find((candidate) => candidate.value === value);

        setChatPreset(value);
        setChatProbeState({ status: 'idle' });

        if (!next) {
            return;
        }

        chatForm.setData((data) => ({
            ...data,
            chat_base_url: next.baseUrl,
            chat_model: next.model,
        }));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setProbeState({ status: 'idle' });
        embeddingsForm.put(route('settings.embeddings.update'), { preserveScroll: true });
    };

    // The chat's own save: its own route and its own reset — saving the
    // chat never resets the embeddings block's unsaved typing, and the
    // other way round.
    const submitChat = (e: React.FormEvent) => {
        e.preventDefault();
        setChatProbeState({ status: 'idle' });
        chatForm.put(route('settings.embeddings.update-chat'), { preserveScroll: true });
    };

    const testConnection = () => {
        setProbeState({ status: 'pending' });

        // The test endpoint answers plain JSON (never Inertia: it runs the
        // exact save-time probe without persisting). It needs the CSRF
        // header manually — Inertia's automatic handling only covers its
        // own visits.
        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

        fetch(route('settings.embeddings.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({
                base_url: embeddingsForm.data.base_url,
                api_key: embeddingsForm.data.api_key,
                model: embeddingsForm.data.model,
                dimensions: embeddingsForm.data.dimensions === '' ? null : embeddingsForm.data.dimensions,
            }),
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: { dimensions: number; latencyMs: number }) => {
                setProbeState({ status: 'ok', dimensions: payload.dimensions, latencyMs: payload.latencyMs });
            })
            .catch(() => setProbeState({ status: 'failed' }));
    };

    const testChat = () => {
        setChatProbeState({ status: 'pending' });

        // The chat's probe: the exact save-time probe, minus the persistence —
        // a minimal message sent to the Conversational provider, the reply
        // content and the latency displayed. The key never travels back.
        const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];

        fetch(route('settings.embeddings.test-chat'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
            },
            body: JSON.stringify({
                chat_base_url: chatForm.data.chat_base_url,
                chat_api_key: chatForm.data.chat_api_key,
                chat_model: chatForm.data.chat_model,
            }),
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: { content: string; latencyMs: number }) => {
                setChatProbeState({ status: 'ok', content: payload.content, latencyMs: payload.latencyMs });
            })
            .catch(() => setChatProbeState({ status: 'failed' }));
    };

    const reembedWorkspace = () => {
        router.post(route('settings.embeddings.reembed'), {}, { preserveScroll: true });
    };

    const selectedPreset = PRESETS.find((candidate) => candidate.value === preset);
    const selectedChatPreset = CHAT_PRESETS.find((candidate) => candidate.value === chatPreset);
    // The chat key field is write-only (empty keeps the stored one): the
    // dots placeholder only reads well while the field is untouched.
    const hasChatKeyInput = chatForm.data.chat_api_key !== null && chatForm.data.chat_api_key !== '';
    const showsPresetWarning =
        selectedPreset !== undefined &&
        selectedPreset.dimensions !== null &&
        selectedPreset.dimensions !== requiredDimensions;
    const showsChatPresetWarning =
        selectedChatPreset !== undefined &&
        selectedChatPreset.model !== null &&
        Boolean(chatProvider) &&
        !chatProvider?.hasApiKey;
    const activeSourceLabel =
        activeProvider.source === 'workspace'
            ? t('embeddingsPage.activeSourceWorkspace')
            : activeProvider.source === 'global'
              ? t('embeddingsPage.activeSourceGlobal')
              : t('embeddingsPage.activeSourceNone');

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('settings.sections.embeddings')}
                description={t('embeddingsPage.description')}
            >
                <Head title={t('settings.sections.embeddings')} />

                <FlashBanner />

                <div className="grid gap-2 rounded-xl border bg-card p-5" data-test="embeddings-active-provider">
                    <h3 className="text-sm font-medium">{t('embeddingsPage.activeTitle')}</h3>
                    <p className="text-sm text-muted-foreground">{activeSourceLabel}</p>
                    {activeProvider.source !== 'none' ? (
                        <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">{t('embeddingsPage.activeBaseUrl')}</dt>
                                <dd className="truncate font-mono">{activeProvider.baseUrl}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">{t('embeddingsPage.activeModel')}</dt>
                                <dd className="font-mono">{activeProvider.model}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">{t('embeddingsPage.activeDimensions')}</dt>
                                <dd>{activeProvider.dimensions}</dd>
                            </div>
                            <div className="flex gap-2">
                                <dt className="text-muted-foreground">API</dt>
                                <dd>
                                    {activeProvider.hasApiKey
                                        ? t('embeddingsPage.activeKeyPresent')
                                        : t('embeddingsPage.activeKeyAbsent')}
                                </dd>
                            </div>
                        </dl>
                    ) : null}
                    <p className="text-sm text-muted-foreground">
                        {t('embeddingsPage.requiredDimensions', { dimensions: requiredDimensions })}
                    </p>
                </div>

                {/* The chat block: the AI settings screen's assistant — the
                    endpoint and the model are presets' pre-fill, the URL
                    stays editable, the key is a write-only field (blank keeps
                    the stored one; both blank = the pair fallback reusing the
                    embeddings pair — the chatProvider summary reports the
                    effective pair). Without any chat settings the block
                    warns with its empty state — an information, never a
                    mask: the form below stays visible so the owner makes
                    the first configuration, and the chat simply stays
                    disabled until saved. */}
                {/* The chat block: the AI settings screen's assistant — the<tool_call>edit<arg_key>newString</arg_key><arg_value>                {/* The chat block: the AI settings screen's assistant — the
                    endpoint and the model are presets' pre-fill, the URL
                    stays editable, the key is a write-only field (blank keeps
                    the stored one; both blank = the pair fallback reusing the
                    embeddings pair — the chatProvider summary reports the
                    effective pair). Without any chat settings the block
                    warns with its empty state — an information, never a
                    mask: the form below stays visible so the owner makes
                    the first configuration, and the chat simply stays
                    disabled until saved. */}
                <form
                    onSubmit={submitChat}
                    className="grid gap-2 rounded-xl border bg-card p-5"
                    data-test="chat-settings"
                >
                    <h3 className="text-sm font-medium">{t('chatPage.settingsTitle')}</h3>
                    <p className="text-sm text-muted-foreground">{t('chatPage.settingsDescription')}</p>
                    {!chatProvider || chatProvider.source === 'none' ? (
                        <p className="text-sm text-muted-foreground" data-test="chat-empty-state">
                            {t('chatPage.description')}
                        </p>
                    ) : (
                        <>
                            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                <div className="flex gap-2">
                                    <dt className="text-muted-foreground">{t('chatPage.endpointLabel')}</dt>
                                    <dd className="truncate font-mono">{chatProvider.baseUrl}</dd>
                                </div>
                                <div className="flex gap-2">
                                    <dt className="text-muted-foreground">{t('chatPage.modelLabel')}</dt>
                                    <dd className="font-mono">{chatProvider.model}</dd>
                                </div>
                                <div className="flex gap-2">
                                    <dt className="text-muted-foreground">API</dt>
                                    <dd>
                                        {chatProvider.hasApiKey
                                            ? t('chatPage.keyPresent')
                                            : t('chatPage.keyAbsent')}
                                    </dd>
                                </div>
                            </dl>
                        {showsChatPresetWarning ? (
                                <p className="text-sm text-muted-foreground" data-test="chat-preset-hint">
                                    {t('chatPage.presetPlaceholder')}
                                </p>
                            ) : null}
                        </>
                    )}
                    {/* The fields, the presets and both actions render at
                        all times: the empty state above is a warning, not a
                        mask — the settings page exists to make the first
                        chat configuration. Non-owners read them disabled. */}
                    <div className="grid gap-2">
                                <Label htmlFor="chat-preset">{t('chatPage.presetLabel')}</Label>
                                <Select
                                    value={chatPreset || undefined}
                                    onValueChange={(value) => value && applyChatPreset(value)}
                                >
                                    <SelectTrigger id="chat-preset" className="w-80" data-test="chat-preset">
                                        <SelectValue>
                                            {selectedChatPreset
                                                ? t(selectedChatPreset.labelKey)
                                                : t('chatPage.presetPlaceholder')}
                                        </SelectValue>
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="mistral">{t('chatPage.presetMistral')}</SelectItem>
                                        <SelectItem value="openai">{t('chatPage.presetOpenAI')}</SelectItem>
                                        <SelectItem value="custom">{t('chatPage.presetCustom')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Label htmlFor="chat-base-url">{t('chatPage.endpointLabel')}</Label>
                                <Input
                                    id="chat-base-url"
                                    type="url"
                                    className="font-mono"
                                    placeholder={t('chatPage.endpointPlaceholder')}
                                    value={chatForm.data.chat_base_url}
                                    onChange={(e) => chatForm.setData('chat_base_url', e.target.value)}
                                    disabled={!isOwner}
                                    aria-invalid={chatForm.errors.chat_base_url ? true : undefined}
                                    data-test="chat-base-url"
                                />
                                {chatForm.errors.chat_base_url ? (
                                    <p className="text-sm text-destructive" data-test="chat-base-url-error">
                                        {chatForm.errors.chat_base_url}
                                    </p>
                                ) : null}
                                <Label htmlFor="chat-model">{t('chatPage.modelLabel')}</Label>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Input
                                        id="chat-model"
                                        className="min-w-0 flex-1 font-mono"
                                        placeholder={t('chatPage.modelPlaceholder')}
                                        value={chatForm.data.chat_model}
                                        onChange={(e) => chatForm.setData('chat_model', e.target.value)}
                                        disabled={!isOwner}
                                        aria-invalid={chatForm.errors.chat_model ? true : undefined}
                                        data-test="chat-model"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={!isOwner || chatModels.status === 'pending'}
                                        onClick={loadChatModels}
                                        data-test="chat-models-load"
                                    >
                                        {chatModels.status === 'pending'
                                            ? t('chatPage.modelsLoading')
                                            : t('chatPage.loadModels')}
                                    </Button>
                                </div>
                                {chatModels.status === 'ok' ? (
                                    <Select
                                        value={chatModelsChoice || undefined}
                                        onValueChange={(value) => {
                                            if (!value) {
                                                return;
                                            }

                                            setChatModelsChoice(value);
                                            chatForm.setData('chat_model', value);
                                            setChatModels({ status: 'idle' });
                                        }}
                                    >
                                        <SelectTrigger id="chat-models-select" className="w-full font-mono" data-test="chat-models-select">
                                            <SelectValue>
                                                {chatModelsChoice ? chatModelsChoice : t('chatPage.modelsChoosePlaceholder')}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent className="max-h-72">
                                            {chatModels.models.map((id) => (
                                                <SelectItem key={id} value={id} className="font-mono">
                                                    {id}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : null}
                                {chatModels.status === 'empty' ? (
                                    <p className="text-sm text-muted-foreground" data-test="chat-models-empty">
                                        {t('chatPage.modelsEmpty')}
                                    </p>
                                ) : null}
                                {chatModels.status === 'failed' ? (
                                    <p className="text-sm text-destructive" data-test="chat-models-error">
                                        {chatModels.message ?? t('chatPage.modelsLoadFailed')}
                                    </p>
                                ) : null}
                                {chatForm.errors.chat_model ? (
                                    <p className="text-sm text-destructive" data-test="chat-model-error">
                                        {chatForm.errors.chat_model}
                                    </p>
                                ) : null}
                                <Label htmlFor="chat-api-key">{t('chatPage.apiKeyLabel')}</Label>
                                <Input
                                    id="chat-api-key"
                                    type="password"
                                    autoComplete="new-password"
                                    className="font-mono"
                                    placeholder={chatProvider?.hasApiKey && !hasChatKeyInput ? '••••••••' : ''}
                                    value={chatForm.data.chat_api_key}
                                    onChange={(e) => chatForm.setData('chat_api_key', e.target.value)}
                                    disabled={!isOwner}
                                    aria-invalid={chatForm.errors.chat_api_key ? true : undefined}
                                    data-test="chat-api-key"
                                />
                                <p className="text-sm text-muted-foreground">{t('chatPage.apiKeyHint')}</p>
                                {chatForm.errors.chat_api_key ? (
                                    <p className="text-sm text-destructive" data-test="chat-api-key-error">
                                        {chatForm.errors.chat_api_key}
                                    </p>
                                ) : null}
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={!isOwner || chatForm.processing}
                                        data-test="chat-save"
                                    >
                                        {t('chatPage.save')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={!isOwner || chatProbeState.status === 'pending'}
                                        onClick={testChat}
                                        data-test="chat-test"
                                    >
                                        {chatProbeState.status === 'pending'
                                            ? t('chatPage.testing')
                                            : t('chatPage.testButton')}
                                    </Button>
                                    {chatProbeState.status === 'ok' ? (
                                        <p className="text-sm" data-test="chat-test-result">
                                            {t('chatPage.testOk', {
                                                content: chatProbeState.content,
                                                latency: chatProbeState.latencyMs,
                                            })}
                                        </p>
                                    ) : null}
                                    {chatProbeState.status === 'failed' ? (
                                        <p className="text-sm text-destructive" data-test="chat-test-failed">
                                            {t('chatPage.testFailed')}
                                        </p>
                                    ) : null}
                                </div>
                                <p className="text-sm text-muted-foreground">{t('chatPage.endpointInfo')}</p>
                            </div>
                </form>

                <form
                    onSubmit={submit}
                    className="grid gap-5 rounded-xl border bg-card p-5"
                    data-test="embeddings-form"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="embeddings-preset">{t('embeddingsPage.presetLabel')}</Label>
                        <Select value={preset || undefined} onValueChange={(value) => value && applyPreset(value)}>
                            <SelectTrigger id="embeddings-preset" className="w-80" data-test="embeddings-preset">
                                <SelectValue>
                                    {selectedPreset
                                        ? t(selectedPreset.labelKey)
                                        : t('embeddingsPage.presetPlaceholder')}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                {PRESETS.map((candidate) => (
                                    <SelectItem key={candidate.value} value={candidate.value}>
                                        {t(candidate.labelKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {showsPresetWarning && selectedPreset ? (
                            <p className="text-sm text-destructive" data-test="embeddings-preset-warning">
                                {t('embeddingsPage.presetDimensionWarning', {
                                    dimensions: selectedPreset.dimensions,
                                    required: requiredDimensions,
                                })}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="embeddings-base-url">{t('embeddingsPage.baseUrlLabel')}</Label>
                        <Input
                            id="embeddings-base-url"
                            type="url"
                            className="font-mono"
                            placeholder={t('embeddingsPage.baseUrlPlaceholder')}
                            value={embeddingsForm.data.base_url}
                            onChange={(e) => embeddingsForm.setData('base_url', e.target.value)}
                            disabled={!isOwner}
                            aria-invalid={embeddingsForm.errors.base_url ? true : undefined}
                            data-test="embeddings-base-url"
                        />
                        {embeddingsForm.errors.base_url ? (
                            <p className="text-sm text-destructive" data-test="embeddings-base-url-error">
                                {embeddingsForm.errors.base_url}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="embeddings-model">{t('embeddingsPage.modelLabel')}</Label>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                id="embeddings-model"
                                className="min-w-0 flex-1 font-mono"
                                value={embeddingsForm.data.model}
                                onChange={(e) => embeddingsForm.setData('model', e.target.value)}
                                disabled={!isOwner}
                                aria-invalid={embeddingsForm.errors.model ? true : undefined}
                                data-test="embeddings-model"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!isOwner || embeddingsModels.status === 'pending'}
                                onClick={loadEmbeddingsModels}
                                data-test="embeddings-models-load"
                            >
                                {embeddingsModels.status === 'pending'
                                    ? t('embeddingsPage.modelsLoading')
                                    : t('embeddingsPage.loadModels')}
                            </Button>
                        </div>
                        {embeddingsModels.status === 'ok' ? (
                            <Select
                                value={embeddingsModelsChoice || undefined}
                                onValueChange={(value) => {
                                    if (!value) {
                                        return;
                                    }

                                    setEmbeddingsModelsChoice(value);
                                    embeddingsForm.setData('model', value);
                                    setEmbeddingsModels({ status: 'idle' });
                                }}
                            >
                                <SelectTrigger id="embeddings-models-select" className="w-full font-mono" data-test="embeddings-models-select">
                                    <SelectValue>
                                        {embeddingsModelsChoice ? embeddingsModelsChoice : t('embeddingsPage.modelsChoosePlaceholder')}
                                    </SelectValue>
                                </SelectTrigger>
                                <SelectContent className="max-h-72">
                                    {embeddingsModels.models.map((id) => (
                                        <SelectItem key={id} value={id} className="font-mono">
                                            {id}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : null}
                        {embeddingsModels.status === 'empty' ? (
                            <p className="text-sm text-muted-foreground" data-test="embeddings-models-empty">
                                {t('embeddingsPage.modelsEmpty')}
                            </p>
                        ) : null}
                        {embeddingsModels.status === 'failed' ? (
                            <p className="text-sm text-destructive" data-test="embeddings-models-error">
                                {embeddingsModels.message ?? t('embeddingsPage.modelsLoadFailed')}
                            </p>
                        ) : null}
                        {embeddingsForm.errors.model ? (
                            <p className="text-sm text-destructive" data-test="embeddings-model-error">
                                {embeddingsForm.errors.model}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="embeddings-api-key">{t('embeddingsPage.apiKeyLabel')}</Label>
                        <Input
                            id="embeddings-api-key"
                            type="password"
                            autoComplete="new-password"
                            className="font-mono"
                            placeholder={settings?.hasApiKey ? '••••••••' : ''}
                            value={embeddingsForm.data.api_key}
                            onChange={(e) => embeddingsForm.setData('api_key', e.target.value)}
                            disabled={!isOwner}
                            aria-invalid={embeddingsForm.errors.api_key ? true : undefined}
                            data-test="embeddings-api-key"
                        />
                        <p className="text-sm text-muted-foreground">{t('embeddingsPage.apiKeyHint')}</p>
                        {embeddingsForm.errors.api_key ? (
                            <p className="text-sm text-destructive" data-test="embeddings-api-key-error">
                                {embeddingsForm.errors.api_key}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="embeddings-dimensions">{t('embeddingsPage.dimensionsLabel')}</Label>
                        <Input
                            id="embeddings-dimensions"
                            type="number"
                            min={1}
                            max={8192}
                            className="w-40"
                            placeholder={String(requiredDimensions)}
                            value={embeddingsForm.data.dimensions}
                            onChange={(e) => embeddingsForm.setData('dimensions', e.target.value === '' ? '' : Number(e.target.value))}
                            disabled={!isOwner}
                            aria-invalid={embeddingsForm.errors.dimensions ? true : undefined}
                            data-test="embeddings-dimensions"
                        />
                        <p className="text-sm text-muted-foreground">{t('embeddingsPage.dimensionsHint')}</p>
                        {embeddingsForm.errors.dimensions ? (
                            <p className="text-sm text-destructive">{embeddingsForm.errors.dimensions}</p>
                        ) : null}
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={!isOwner || embeddingsForm.processing} data-test="embeddings-save">
                            {t('embeddingsPage.save')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!isOwner || probeState.status === 'pending'}
                            onClick={testConnection}
                            data-test="embeddings-test"
                        >
                            {probeState.status === 'pending'
                                ? t('embeddingsPage.testing')
                                : t('embeddingsPage.testButton')}
                        </Button>
                        {probeState.status === 'ok' ? (
                            <p className="text-sm" data-test="embeddings-test-result">
                                {t('embeddingsPage.testOk', {
                                    dimensions: probeState.dimensions,
                                    latency: probeState.latencyMs,
                                })}
                            </p>
                        ) : null}
                        {probeState.status === 'failed' ? (
                            <p className="text-sm text-destructive" data-test="embeddings-test-failed">
                                {t('embeddingsPage.testFailed')}
                            </p>
                        ) : null}
                    </div>
                </form>

                <div className="grid gap-2 rounded-xl border bg-card p-5" data-test="embeddings-reembed-card">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium">{t('embeddingsPage.reembed')}</p>
                            <p className="text-sm text-muted-foreground">{t('embeddingsPage.reembedHint')}</p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!isOwner}
                            onClick={reembedWorkspace}
                            data-test="embeddings-reembed"
                        >
                            {t('embeddingsPage.reembed')}
                        </Button>
                    </div>
                </div>
            </SettingsShell>
        </AppLayout>
    );
}
