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
    /** Current filter value; '' selects the "all" sentinel. */
    value: string;
    options: FilterOption[];
    allLabel: string;
    /** Called with '' when the "all" entry is chosen. */
    onChange: (value: string) => void;
    className?: string;
    /** data-test hook used by the browser suite. */
    testId?: string;
};

/**
 * List-filter select reading its value from the URL filters: an "all"
 * sentinel mapped to '' and the selected option's label as display value.
 */
export function FilterSelect({ value, options, allLabel, onChange, className = 'w-44', testId }: FilterSelectProps) {
    const selected = options.find((option) => option.value === value);

    return (
        <Select
            value={value || ALL}
            onValueChange={(next) => next && onChange(next === ALL ? '' : next)}
        >
            <SelectTrigger size="sm" className={className} data-test={testId}>
                <SelectValue>{selected?.label ?? allLabel}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
