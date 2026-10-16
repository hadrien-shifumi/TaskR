import AppLayout from '@/layouts/app-layout';
import { Banner } from '@/components/banner';
import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { formatDate } from '@/lib/format-date';

interface TokenRow {
    id: number;
    name: string;
    createdAt: string;
    lastUsedAt?: string | null;
}

export default function Tokens() {
    const { tokens } = usePage<{ tokens: TokenRow[] }>().props;
    const { flash } = usePage<{ flash?: { status?: string; newToken?: string } }>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    const [creating, setCreating] = useState(false);
    const [copied, setCopied] = useState(false);

    const createForm = useForm({ name: '' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(route('tokens.store'), {
            onSuccess: () => {
                setCreating(false);
                setCopied(false);
                createForm.reset();
            },
        });
    };

    const revoke = (token: TokenRow) => {
        if (!window.confirm(t('tokensPage.revokeConfirm', { name: token.name }))) return;
        router.delete(route('tokens.destroy', token.id));
    };

    const copyToken = async () => {
        if (!flash?.newToken) return;
        try {
            await navigator.clipboard.writeText(flash.newToken);
            setCopied(true);
        } catch {
            // Clipboard unavailable (permissions, insecure context): the
            // value stays visible for a manual copy.
        }
    };

    // Dismissing the card reloads the page: the one-shot flash value is
    // consumed on the next render and the card disappears.
    const dismissCreatedToken = () => {
        router.get(route('settings.tokens'));
    };

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('settings.sections.tokens')}
                description={t('tokensPage.description')}
                memberReadOnly={false}
                actions={
                    <Button size="sm" onClick={() => setCreating(true)}>
                        {t('tokensPage.newToken')}
                    </Button>
                }
            >
                <Head title={t('settings.sections.tokens')} />

            <FlashBanner />

            <Banner variant="warning" className="mb-4">
                {t('tokensPage.bannerPrefix')} <strong>{t('tokensPage.bannerWord')}</strong>
                {t('tokensPage.bannerSuffix')}
            </Banner>

            {flash?.newToken ? (
                <div className="mb-4 rounded-xl border bg-card p-5" data-test="token-created-card">
                    <p className="mb-2 text-sm text-muted-foreground">
                        {t('tokensPage.createdNow')}{' '}
                        <strong className="font-medium text-foreground">{t('tokensPage.copyNow')}</strong>
                    </p>
                    <div
                        data-test="token-created-value"
                        className="rounded-lg border border-dashed bg-muted/50 px-3 py-2.5 font-mono text-[12.5px] break-all"
                    >
                        {flash.newToken}
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={copyToken}>
                            {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
                            {copied ? t('issueShow.copied') : t('tokensPage.copy')}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={dismissCreatedToken}>
                            {t('tokensPage.iveCopied')}
                        </Button>
                    </div>
                </div>
            ) : null}

            <div className="overflow-hidden rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('common.name')}</TableHead>
                            <TableHead>{t('tokensPage.columnCreated')}</TableHead>
                            <TableHead>{t('tokensPage.columnLastUsed')}</TableHead>
                            <TableHead className="w-16" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tokens.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} className="text-muted-foreground">
                                    {t('tokensPage.noTokens')}
                                </TableCell>
                            </TableRow>
                        ) : null}
                        {tokens.map((token) => (
                            <TableRow key={token.id}>
                                <TableCell className="font-medium">{token.name}</TableCell>
                                <TableCell className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {formatDate(token.createdAt)}
                                </TableCell>
                                <TableCell className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {formatDate(token.lastUsedAt ?? null)}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() => revoke(token)}
                                    >
                                        {t('tokensPage.revoke')}
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('tokensPage.newTokenTitle')}</DialogTitle>
                        <DialogDescription>{t('tokensPage.newTokenDescription')}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="token-name">{t('common.name')}</Label>
                            <Input
                                id="token-name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                placeholder="Agent Claude"
                                required
                                autoFocus
                                aria-invalid={createForm.errors.name ? true : undefined}
                            />
                            {createForm.errors.name ? (
                                <p className="text-sm text-destructive">{createForm.errors.name}</p>
                            ) : null}
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={createForm.processing}>
                                {t('tokensPage.createToken')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            </SettingsShell>
        </AppLayout>
    );
}
