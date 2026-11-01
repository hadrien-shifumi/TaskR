import { TableHead } from '@/components/ui/table';
import { cn } from 'cn';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/** Sort keys accepted by the issues list (mirrors ListIssues::SORT_KEYS). */
export type SortKey = 'created' | 'priority' | 'due_date' | 'status' | 'project';

type SortableHeadProps = {
    /** Sort key carried by this column. */
    sortKey: SortKey;
    /** Label of the column, also used in the aria labels. */
    label: string;
    /** Currently active sort key; `created` is the default order. */
    activeSort: SortKey;
    /** Currently active direction. */
    activeDir: 'asc' | 'desc';
    /** Called when the header is clicked, with the next URL params. */
    onSort: (sort: SortKey, dir: 'asc' | 'desc') => void;
    /** Extra classes for the header cell (responsive hiding, widths). */
    className?: string;
};

/** Table header cell that sorts the list: clicking an inactive column
 *  sorts it ascending, clicking the active one flips the direction. */
export function SortableHead({ sortKey, label, activeSort, activeDir, onSort, className }: SortableHeadProps) {
    const { t } = useTranslation();

    const isActive = activeSort === sortKey;
    const nextDir: 'asc' | 'desc' = isActive && activeDir === 'asc' ? 'desc' : 'asc';

    const ariaLabel = isActive
        ? t(activeDir === 'asc' ? 'issuesPage.sort.ariaAsc' : 'issuesPage.sort.ariaDesc', { column: label })
        : t('issuesPage.sort.aria', { column: label });

    const Icon = !isActive ? ArrowUpDown : activeDir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead className={className} aria-sort={isActive ? (activeDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button
                type="button"
                onClick={() => onSort(sortKey, nextDir)}
                aria-label={ariaLabel}
                data-test={`sort-${sortKey.replace('_', '-')}`}
                className={cn(
                    'inline-flex items-center gap-1 rounded-sm text-left hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    isActive ? 'text-foreground' : 'text-muted-foreground',
                )}
            >
                {label}
                <Icon className="size-3.5" aria-hidden />
            </button>
        </TableHead>
    );
}
