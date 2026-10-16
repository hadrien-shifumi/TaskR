import { Button } from '@/components/ui/button';
import { useTranslation } from 'react-i18next';

type LabelOption = {
    id: string;
    name: string;
};

type LabelPickerProps = {
    labels: LabelOption[];
    selectedIds: string[];
    onToggle: (labelId: string) => void;
    /** Prefix for per-label input ids, when a form label points at them. */
    idPrefix?: string;
};

/**
 * Toggleable label chips for issue/template forms. Selected state is
 * announced with aria-pressed; the list itself is rendered by the parent
 * form.
 */
export function LabelPicker({ labels, selectedIds, onToggle, idPrefix }: LabelPickerProps) {
    const { t } = useTranslation();

    if (labels.length === 0) {
        return <p className="text-sm text-muted-foreground">{t('labelPicker.noLabels')}</p>;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {labels.map((label) => {
                const selected = selectedIds.includes(label.id);
                return (
                    <Button
                        key={label.id}
                        id={idPrefix ? `${idPrefix}-${label.id}` : undefined}
                        type="button"
                        variant={selected ? 'default' : 'outline'}
                        size="sm"
                        aria-pressed={selected}
                        onClick={() => onToggle(label.id)}
                    >
                        {label.name}
                    </Button>
                );
            })}
        </div>
    );
}
