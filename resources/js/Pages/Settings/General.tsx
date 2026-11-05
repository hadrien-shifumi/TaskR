import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';

type PageProps = {
    parentsAsContainers: boolean;
};

export default function General() {
    const { parentsAsContainers } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const isOwner = workspace?.role === 'owner';

    const toggle = (checked: boolean) => {
        router.put(route('settings.general.update'), { parents_as_containers: checked }, { preserveScroll: true });
    };

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('settings.generalTitle')}
                description={t('settings.generalDescription')}
            >
                <Head title={t('settings.sections.general')} />

                <FlashBanner />

                <div className="grid gap-2 rounded-xl border bg-card p-5" data-test="parents-as-containers-card">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="parents-as-containers"
                            data-test="parents-as-containers-toggle"
                            aria-label={t('settings.parentsAsContainersLabel')}
                            checked={parentsAsContainers}
                            disabled={!isOwner}
                            onCheckedChange={(checked) => toggle(checked === true)}
                        />
                        <Label htmlFor="parents-as-containers" className="text-sm font-normal">
                            {t('settings.parentsAsContainersLabel')}
                        </Label>
                    </div>
                    <p className="text-sm text-muted-foreground">{t('settings.parentsAsContainersHint')}</p>
                </div>
            </SettingsShell>
        </AppLayout>
    );
}
