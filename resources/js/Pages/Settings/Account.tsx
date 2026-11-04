import AppLayout from '@/layouts/app-layout';
import { Banner } from '@/components/banner';
import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Account() {
    const { t } = useTranslation();

    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('user.password.update'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <AppLayout title={t('settings.sections.account')} narrow>
            <SettingsShell
                title={t('settings.sections.account')}
                description={t('accountPage.description')}
                memberReadOnly={false}
            >
                <Head title={t('settings.sections.account')} />

                <FlashBanner />

                <Banner variant="warning" data-test="account-security-hint">
                    {t('accountPage.securityHint')}{' '}
                    <Link
                        href={route('settings.tokens')}
                        className="font-medium underline underline-offset-2"
                        data-test="account-security-hint-link"
                    >
                        {t('accountPage.securityHintLink')}
                    </Link>
                </Banner>

                <form
                    onSubmit={submit}
                    className="grid gap-5 rounded-xl border bg-card p-5"
                    data-test="account-password-form"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="account-current-password">{t('accountPage.currentPassword')}</Label>
                        <Input
                            id="account-current-password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(e) => form.setData('current_password', e.target.value)}
                            aria-invalid={form.errors.current_password ? true : undefined}
                            data-test="account-current-password"
                        />
                        {form.errors.current_password ? (
                            <p className="text-sm text-destructive">{form.errors.current_password}</p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="account-new-password">{t('accountPage.newPassword')}</Label>
                        <Input
                            id="account-new-password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            aria-invalid={form.errors.password ? true : undefined}
                            data-test="account-new-password"
                        />
                        <p className="text-sm text-muted-foreground">{t('accountPage.passwordHint')}</p>
                        {form.errors.password ? (
                            <p className="text-sm text-destructive">{form.errors.password}</p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="account-confirm-password">{t('accountPage.confirmPassword')}</Label>
                        <Input
                            id="account-confirm-password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            data-test="account-confirm-password"
                        />
                    </div>

                    <div>
                        <Button type="submit" disabled={form.processing} data-test="account-save">
                            {t('accountPage.submit')}
                        </Button>
                    </div>
                </form>
            </SettingsShell>
        </AppLayout>
    );
}
