import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from 'react-i18next';

export const PRIORITIES = ['none', 'low', 'high', 'urgent'];

type PrioritySelectProps = {
    id?: string;
    value: string;
    onValueChange: (value: string) => void;
};

/** Issue priority picker over the fixed priority scale. */
export function PrioritySelect({ id, value, onValueChange }: PrioritySelectProps) {
    const { t } = useTranslation();

    return (
        <Select
            value={value}
            onValueChange={(v) => {
                if (v) onValueChange(v);
            }}
        >
            <SelectTrigger id={id}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {PRIORITIES.map((priority) => (
                    <SelectItem key={priority} value={priority}>
                        {t(`priority.${priority}`)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
