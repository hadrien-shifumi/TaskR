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

export default function Embeddings() {
    const { settings, activeProvider, requiredDimensions } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const isOwner = workspace?.role === 'owner';

    const form = useForm({
        base_url: settings?.baseUrl ?? '',
        api_key: '',
        model: settings?.model ?? '',
        dimensions: settings?.sendDimensions ? (settings?.dimensions ?? null) : ('' as number | ''),
    });

    const [preset, setPreset] = useState<string>(settings ? 'custom' : '');
    const [probeState, setProbeState] = useState<
        { status: 'idle' } | { status: 'pending' } | { status: 'ok'; dimensions: number; latencyMs: number } | { status: 'failed' }
    >({ status: 'idle' });

    // Magic value: an empty write-only key field keeps the stored key.
    const applyPreset = (value: string) => {
        const next = PRESETS.find((candidate) => candidate.value === value);

        setPreset(value);
        setProbeState({ status: 'idle' });

        if (!next) {
            return;
        }

        form.setData((data) => ({
            ...data,
            base_url: next.baseUrl,
            model: next.model,
            api_key: next.apiKey ?? data.api_key,
        }));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setProbeState({ status: 'idle' });
        form.put(route('settings.embeddings.update'), { preserveScroll: true });
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
                base_url: form.data.base_url,
                api_key: form.data.api_key,
                model: form.data.model,
                dimensions: form.data.dimensions === '' ? null : form.data.dimensions,
            }),
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: { dimensions: number; latencyMs: number }) => {
                setProbeState({ status: 'ok', dimensions: payload.dimensions, latencyMs: payload.latencyMs });
            })
            .catch(() => setProbeState({ status: 'failed' }));
    };

    const reembedWorkspace = () => {
        router.post(route('settings.embeddings.reembed'), {}, { preserveScroll: true });
    };

    const selectedPreset = PRESETS.find((candidate) => candidate.value === preset);
    const showsPresetWarning =
        selectedPreset !== undefined &&
        selectedPreset.dimensions !== null &&
        selectedPreset.dimensions !== requiredDimensions;

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
                            value={form.data.base_url}
                            onChange={(e) => form.setData('base_url', e.target.value)}
                            disabled={!isOwner}
                            aria-invalid={form.errors.base_url ? true : undefined}
                            data-test="embeddings-base-url"
                        />
                        {form.errors.base_url ? (
                            <p className="text-sm text-destructive" data-test="embeddings-base-url-error">
                                {form.errors.base_url}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="embeddings-model">{t('embeddingsPage.modelLabel')}</Label>
                        <Input
                            id="embeddings-model"
                            className="font-mono"
                            value={form.data.model}
                            onChange={(e) => form.setData('model', e.target.value)}
                            disabled={!isOwner}
                            aria-invalid={form.errors.model ? true : undefined}
                            data-test="embeddings-model"
                        />
                        {form.errors.model ? (
                            <p className="text-sm text-destructive" data-test="embeddings-model-error">
                                {form.errors.model}
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
                            value={form.data.api_key}
                            onChange={(e) => form.setData('api_key', e.target.value)}
                            disabled={!isOwner}
                            aria-invalid={form.errors.api_key ? true : undefined}
                            data-test="embeddings-api-key"
                        />
                        <p className="text-sm text-muted-foreground">{t('embeddingsPage.apiKeyHint')}</p>
                        {form.errors.api_key ? (
                            <p className="text-sm text-destructive" data-test="embeddings-api-key-error">
                                {form.errors.api_key}
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
                            value={form.data.dimensions}
                            onChange={(e) => form.setData('dimensions', e.target.value === '' ? '' : Number(e.target.value))}
                            disabled={!isOwner}
                            aria-invalid={form.errors.dimensions ? true : undefined}
                            data-test="embeddings-dimensions"
                        />
                        <p className="text-sm text-muted-foreground">{t('embeddingsPage.dimensionsHint')}</p>
                        {form.errors.dimensions ? (
                            <p className="text-sm text-destructive">{form.errors.dimensions}</p>
                        ) : null}
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={!isOwner || form.processing} data-test="embeddings-save">
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
