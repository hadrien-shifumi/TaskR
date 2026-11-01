import AppLayout from '@/layouts/app-layout';
import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';

type DigestFrequency = 'daily' | 'weekly';

type NotificationPreferences = {
    enabled: boolean;
    frequency: DigestFrequency;
    upcomingThresholdDays: number;
    timezone: string;
    lastDigestAt?: string | null;
};

type PageProps = {
    preferences: NotificationPreferences;
    timezones: string[];
};

export default function Notifications() {
    const { preferences, timezones } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        enabled: preferences.enabled,
        frequency: preferences.frequency,
        upcoming_threshold_days: preferences.upcomingThresholdDays,
        timezone: preferences.timezone,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('settings.notifications.update'), { preserveScroll: true });
    };

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('settings.sections.notifications')}
                description={t('notificationsPage.description')}
                memberReadOnly={false}
            >
                <Head title={t('settings.sections.notifications')} />

                <FlashBanner />

                <form
                    onSubmit={submit}
                    className="grid gap-5 rounded-xl border bg-card p-5"
                    data-test="notification-preferences-form"
                >
                    <div className="grid gap-2">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="notification-enabled"
                                checked={form.data.enabled}
                                onCheckedChange={(checked) => form.setData('enabled', checked === true)}
                                data-test="notification-opt-in"
                            />
                            <Label htmlFor="notification-enabled" className="text-sm font-normal">
                                {t('notificationsPage.optInLabel')}
                            </Label>
                        </div>
                        <p className="text-sm text-muted-foreground">{t('notificationsPage.optInHint')}</p>
                        {form.errors.enabled ? <p className="text-sm text-destructive">{form.errors.enabled}</p> : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notification-frequency">{t('notificationsPage.frequencyLabel')}</Label>
                        <Select
                            value={form.data.frequency}
                            onValueChange={(value) => value && form.setData('frequency', value as DigestFrequency)}
                        >
                            <SelectTrigger id="notification-frequency" className="w-48" data-test="notification-frequency">
                                <SelectValue>
                                    {form.data.frequency === 'weekly'
                                        ? t('notificationsPage.frequencyWeekly')
                                        : t('notificationsPage.frequencyDaily')}
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="daily">{t('notificationsPage.frequencyDaily')}</SelectItem>
                                <SelectItem value="weekly">{t('notificationsPage.frequencyWeekly')}</SelectItem>
                            </SelectContent>
                        </Select>
                        {form.errors.frequency ? (
                            <p className="text-sm text-destructive">{form.errors.frequency}</p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notification-threshold">{t('notificationsPage.thresholdLabel')}</Label>
                        <Input
                            id="notification-threshold"
                            type="number"
                            min={1}
                            max={90}
                            className="w-32"
                            value={form.data.upcoming_threshold_days}
                            onChange={(e) => form.setData('upcoming_threshold_days', Number(e.target.value))}
                            aria-invalid={form.errors.upcoming_threshold_days ? true : undefined}
                            data-test="notification-threshold"
                        />
                        <p className="text-sm text-muted-foreground">{t('notificationsPage.thresholdHint')}</p>
                        {form.errors.upcoming_threshold_days ? (
                            <p className="text-sm text-destructive">{form.errors.upcoming_threshold_days}</p>
                        ) : null}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notification-timezone">{t('notificationsPage.timezoneLabel')}</Label>
                        <Select
                            value={form.data.timezone}
                            onValueChange={(value) => value && form.setData('timezone', value)}
                        >
                            <SelectTrigger id="notification-timezone" className="w-72" data-test="notification-timezone">
                                <SelectValue>{form.data.timezone}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                {timezones.map((timezone) => (
                                    <SelectItem key={timezone} value={timezone}>
                                        {timezone}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.timezone ? (
                            <p className="text-sm text-destructive">{form.errors.timezone}</p>
                        ) : null}
                    </div>

                    <p className="text-sm text-muted-foreground">{t('notificationsPage.optOutHint')}</p>

                    <div>
                        <Button type="submit" disabled={form.processing} data-test="notification-save">
                            {t('notificationsPage.save')}
                        </Button>
                    </div>
                </form>
            </SettingsShell>
        </AppLayout>
    );
}
