import { FlashBanner } from '@/components/flash-banner';
import { SettingsShell } from '@/components/settings-shell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps } from '@/types';
import { DeleteStatusDialog } from './Statuses/components/delete-status-dialog';
import { StatusFormDialog } from './Statuses/components/status-form-dialog';
import { StatusRowItem } from './Statuses/components/status-row';
import type { StatusRow } from './Statuses/types';

type PageProps = {
    statuses: StatusRow[];
};

export default function Statuses() {
    const { statuses } = usePage<PageProps>().props;
    const { workspace } = usePage<PageSharedProps>().props;
    const { t } = useTranslation();
    const isOwner = workspace?.role === 'owner';
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<StatusRow | null>(null);
    const [deleting, setDeleting] = useState<StatusRow | null>(null);
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [overIndex, setOverIndex] = useState<number | null>(null);

    const reorder = (ids: string[]) => {
        router.post(route('statuses.reorder'), { ids });
    };

    const dropOn = (index: number) => {
        if (dragIndex === null || dragIndex === index) {
            setDragIndex(null);
            setOverIndex(null);
            return;
        }
        const ids = statuses.map((s) => s.id);
        const [moved] = ids.splice(dragIndex, 1);
        ids.splice(index, 0, moved);
        reorder(ids);
        setDragIndex(null);
        setOverIndex(null);
    };

    return (
        <AppLayout title={t('settings.pageTitle')} subtitle={workspace?.name} narrow>
            <SettingsShell
                title={t('settings.statusesTitle')}
                description={t('settings.statusesDescription')}
                actions={
                    <Button size="sm" onClick={() => setCreating(true)}>
                        {t('settings.newStatus')}
                    </Button>
                }
            >
                <Head title={t('settings.sections.statuses')} />

                <FlashBanner />

                <div className="space-y-2">
                    {statuses.map((status, index) => (
                        <StatusRowItem
                            key={status.id}
                            status={status}
                            isOwner={isOwner}
                            dragging={dragIndex === index}
                            over={overIndex === index && dragIndex !== null && dragIndex !== index}
                            onDragStart={() => setDragIndex(index)}
                            onDragEnd={() => {
                                setDragIndex(null);
                                setOverIndex(null);
                            }}
                            onDragOver={(e) => {
                                if (dragIndex === null) return;
                                e.preventDefault();
                                setOverIndex(index);
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                dropOn(index);
                            }}
                            onEdit={() => setEditing(status)}
                            onDelete={() => setDeleting(status)}
                        />
                    ))}
                </div>

                <p className="mt-3.5 text-sm text-muted-foreground">
                    {t('settings.statusesNote')}
                </p>

                <StatusFormDialog
                    open={creating || editing !== null}
                    status={editing}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />

                <DeleteStatusDialog
                    status={deleting}
                    statuses={statuses}
                    onClose={() => setDeleting(null)}
                />
            </SettingsShell>
        </AppLayout>
    );
}
