import { Input } from '@/components/ui/input';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';

type FilterBarProps = {
    /** Current title search, mirrored into the `q` URL param by the page. */
    search: string;
    onSearchChange: (value: string) => void;
    searchPlaceholder: string;
    searchAriaLabel: string;
    /** Width class for the search input; defaults to the compact w-50. */
    searchClassName?: string;
    /** Rows currently displayed… */
    visible: number;
    /** …out of the workspace total (unfiltered). */
    total: number;
    /** Filter chips, rendered between the search field and the counter. */
    children?: ReactNode;
};

/**
 * Shared filter bar (maquette `.filterbar`): title search with a leading
 * icon, cumulative filter chips, and a mono "visible / total" counter pinned
 * to the right. The bar is presentational — pages own the URL state.
 */
export function FilterBar({
    search,
    onSearchChange,
    searchPlaceholder,
    searchAriaLabel,
    searchClassName = 'w-50',
    visible,
    total,
    children,
}: FilterBarProps) {
    return (
        <div className="mb-3.5 flex flex-wrap items-center gap-2" data-test="filter-bar">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute top-2 left-2.5 size-3.5 text-muted-foreground"
                    aria-hidden
                />
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => onSearchChange(event.target.value)}
                    placeholder={searchPlaceholder}
                    aria-label={searchAriaLabel}
                    data-test="filter-search"
                    className={`h-7.5 ${searchClassName} rounded-md pl-8 text-[13px]`}
                />
            </div>
            {children}
            <span
                data-test="filter-count"
                className="ml-auto font-mono text-[13px] text-muted-foreground tabular-nums"
            >
                {visible} / {total}
            </span>
        </div>
    );
}
