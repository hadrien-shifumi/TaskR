import { FormDialog } from '@/components/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { StatusRow } from '../types';

const CATEGORIES = ['backlog', 'in_progress', 'done', 'cancelled'];

type StatusFormDialogProps = {
    open: boolean;
    /** Status being edited, or null in create mode. */
    status: StatusRow | null;
    onClose: () => void;
};

/** Create/edit dialog for a workspace status; `status` selects the mode. */
export function StatusFormDialog({ open, status, onClose }: StatusFormDialogProps) {
    const { t } = useTranslation();
    const form = useForm({ name: '', category: 'backlog' });

    // Seed at open: edit mode reuses the row's values, create mode resets.
    useEffect(() => {
        if (status) {
            form.setData({ name: status.name, category: status.category });
        } else {
            form.reset();
        }
    }, [status]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (status) {
            form.patch(route('statuses.update', status.id), {
                onSuccess: () => onClose(),
            });
            return;
        }
        form.post(route('statuses.store'), {
            onSuccess: () => {
                onClose();
                form.reset();
            },
        });
    };

    return (
        <FormDialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title={status ? t('settings.statusEditTitle') : t('settings.statusNewTitle')}
            description={status ? t('settings.statusEditDescription') : t('settings.statusNewDescription')}
            submitLabel={status ? t('common.save') : t('common.create')}
            processing={form.processing}
            onSubmit={submit}
        >
            <div className="grid gap-2">
                <Label htmlFor={status ? 'edit-name' : 'new-name'}>{t('common.name')}</Label>
                <Input
                    id={status ? 'edit-name' : 'new-name'}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                    aria-invalid={form.errors.name ? true : undefined}
                />
                {form.errors.name ? (
                    <p className="text-sm text-destructive">{form.errors.name}</p>
                ) : null}
            </div>
            <div className="grid gap-2">
                <Label>{t('settings.categoryLabel')}</Label>
                <Select
                    value={form.data.category}
                    onValueChange={(v) => {
                        if (v) form.setData('category', v);
                    }}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {CATEGORIES.map((category) => (
                            <SelectItem key={category} value={category}>
                                {t(`statusCategory.${category}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                    {t('settings.categoryNote')}
                </p>
            </div>
        </FormDialog>
    );
}
