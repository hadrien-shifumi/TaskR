import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ChevronDown } from 'lucide-react';
import { useTranslation } from 'react-i18next';

type FilterOption = {
    value: string;
    label: string;
};

type FilterMultiSelectProps = {
    /** Currently selected option values; [] means "all". */
    values: string[];
    options: FilterOption[];
    allLabel: string;
    /** Called with [] when the selection is cleared (the URL param is removed). */
    onChange: (values: string[]) => void;
    className?: string;
    /** data-test hook used by the browser suite; options get `${testId}-option`. */
    testId?: string;
    /** Accessible name announced for the trigger button. */
    ariaLabel?: string;
};

/**
 * List-filter multi-select reading its values from the URL filters: toggling
 * an option adds or removes its value, clearing removes the whole param. The
 * trigger shows the "all" label or the number of selected options.
 */
export function FilterMultiSelect({
    values,
    options,
    allLabel,
    onChange,
    className = 'w-36',
    testId,
    ariaLabel,
}: FilterMultiSelectProps) {
    const { t } = useTranslation();

    const toggle = (value: string, checked: boolean) => {
        onChange(checked ? [...values, value] : values.filter((current) => current !== value));
    };

    const triggerLabel =
        values.length === 0
            ? allLabel
            : t('issuesPage.filters.selectedCount', { count: values.length });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                data-test={testId}
                aria-label={ariaLabel}
                className={`inline-flex w-fit items-center justify-between gap-1.5 rounded-[min(var(--radius-md),10px)] border border-input bg-transparent py-2 pr-2 pl-2.5 text-sm whitespace-nowrap transition-colors outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-input/30 dark:hover:bg-input/50 ${className}`}
            >
                <span data-slot="filter-multi-select-value" className="line-clamp-1 flex-1 text-left">
                    {triggerLabel}
                </span>
                <ChevronDown className="pointer-events-none size-4 shrink-0 text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                {options.map((option) => (
                    <DropdownMenuItem
                        key={option.value}
                        closeOnClick={false}
                        data-test={testId ? `${testId}-option` : undefined}
                        onClick={() => toggle(option.value, !values.includes(option.value))}
                    >
                        <Checkbox
                            checked={values.includes(option.value)}
                            aria-hidden
                            tabIndex={-1}
                            className="pointer-events-none"
                        />
                        <span className="min-w-0 truncate">{option.label}</span>
                    </DropdownMenuItem>
                ))}
                {values.length > 0 ? (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-test={testId ? `${testId}-clear` : undefined}
                            onClick={() => onChange([])}
                        >
                            <span className="text-muted-foreground">
                                {t('issuesPage.filters.clearSelection')}
                            </span>
                        </DropdownMenuItem>
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
