import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const ALL = 'all';

type FilterOption = {
    value: string;
    label: string;
};

type FilterSelectProps = {
    /** Current filter value. */
    value: string;
    options: FilterOption[];
    /** Label of the "all" entry mapped to ''. Omit for placeholder mode. */
    allLabel?: string;
    /** Placeholder mode: shown instead of "all" when nothing matches. */
    placeholder?: string;
    /** Called with '' when the "all" entry is chosen (allLabel mode). */
    onChange: (value: string) => void;
    className?: string;
    /** data-test hook used by the browser suite */
    testId?: string;
    /** Accessible name announced for the trigger button. */
    ariaLabel?: string;
};

/**
 * List-filter select reading its value from the URL filters. Two modes:
 * with `allLabel`, an "all" sentinel entry maps '' to "everything"; with
 * `placeholder` only (no allLabel), an empty value means "nothing picked
 * yet" and no all entry exists — one scope at a time.
 */
export function FilterSelect({
    value,
    options,
    allLabel,
    placeholder,
    onChange,
    className = 'w-44',
    testId,
    ariaLabel,
}: FilterSelectProps) {
    const hasAll = allLabel !== undefined;
    const selected = options.find((option) => option.value === value);

    return (
        <Select
            value={hasAll ? value || ALL : value || null}
            onValueChange={(next) => {
                if (!next) {
                    return;
                }
                onChange(hasAll && next === ALL ? '' : next);
            }}
        >
            <SelectTrigger size="sm" className={className} data-test={testId} aria-label={ariaLabel}>
                <SelectValue>{selected?.label ?? (hasAll ? allLabel : placeholder)}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                {hasAll && <SelectItem value={ALL}>{allLabel}</SelectItem>}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
