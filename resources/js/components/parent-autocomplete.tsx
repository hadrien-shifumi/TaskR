import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

/** Row of a parent search endpoint JSON payload. */
export type ParentSearchResult = {
    id: string;
    title: string;
};

type ParentAutocompleteProps = {
    /** Selected parent id; the 'none' sentinel means "no parent". */
    value: string;
    onChange: (id: string) => void;
    /** Title of the currently attached parent — displayed as the selected value. */
    currentTitle?: string | null;
    /** Debounced live search; empty query clears the results. */
    search: (query: string) => Promise<ParentSearchResult[]>;
    /** Candidate ids already in use (the dialog's own issue / milestone). */
    excludeIds?: string[];
    /** i18n key of the field label. */
    labelKey: string;
    /** i18n key of the input placeholder (the "no parent" wording when unset). */
    placeholderKey: string;
    /** i18n key of the empty results row. */
    emptyKey: string;
    /** i18n key of the loading row. */
    loadingKey: string;
    /** i18n key of the "no parent" option. */
    noParentKey: string;
    /** Id of the underlying input. */
    inputId: string;
    /** data-test of the input. */
    inputTestId: string;
    /** data-test of the results list. */
    resultsTestId: string;
    /** data-test of a result row. */
    resultTestId: string;
    /** data-test of the empty results row. */
    emptyTestId: string;
    /** data-test of the "no parent" row. */
    noneTestId: string;
};

/**
 * Live parent autocomplete: debounced LIKE search, results picked from a
 * list, "no parent" option reachable while the query is empty. The cycle
 * rule lives in the mutation action — the dialogs surface its 422 refusal
 * as a danger banner inside the dialog.
 */
export function ParentAutocomplete({
    value,
    onChange,
    currentTitle,
    search,
    excludeIds = [],
    labelKey,
    placeholderKey,
    emptyKey,
    loadingKey,
    noParentKey,
    inputId,
    inputTestId,
    resultsTestId,
    resultTestId,
    emptyTestId,
    noneTestId,
}: ParentAutocompleteProps) {
    const { t } = useTranslation();
    const [query, setQuery] = useState(() => (value === 'none' ? '' : currentTitle ?? ''));
    const [results, setResults] = useState<ParentSearchResult[]>([]);
    const [searching, setSearching] = useState(false);
    const latestRequest = useRef(0);
    const searchRef = useRef(search);

    useEffect(() => {
        searchRef.current = search;
    }, [search]);

    const trimmed = query.trim();

    // Debounced live search: fires 300 ms after the last keystroke, mirroring
    // the add-line dialog. Emptying the box clears the results.
    useEffect(() => {
        if (trimmed === '') {
            setResults([]);
            setSearching(false);
            return;
        }

        const requestId = ++latestRequest.current;
        setSearching(true);
        const timer = setTimeout(() => {
            void Promise.resolve()
                .then(() => searchRef.current(trimmed))
                .then((found) => {
                    if (latestRequest.current !== requestId) return;
                    setResults(found);
                    setSearching(false);
                })
                .catch(() => {
                    if (latestRequest.current !== requestId) return;
                    setResults([]);
                    setSearching(false);
                });
        }, 300);

        return () => clearTimeout(timer);
    }, [trimmed]);

    const available = results.filter((result) => !excludeIds.includes(result.id));

    return (
        <div className="grid gap-2">
            <Label htmlFor={inputId}>{t(labelKey)}</Label>
            <Input
                id={inputId}
                data-test={inputTestId}
                autoComplete="off"
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={value === 'none' ? t(noParentKey) : t(placeholderKey)}
            />
            <ul className="max-h-72 divide-y overflow-y-auto rounded-lg border" data-test={resultsTestId}>
                {trimmed === '' ? (
                    <li data-test={noneTestId}>
                        <button
                            type="button"
                            onClick={() => onChange('none')}
                            className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-[13px] transition-colors hover:bg-secondary/60"
                        >
                            <span className="min-w-0 flex-1 truncate">{t(noParentKey)}</span>
                        </button>
                    </li>
                ) : null}
                {available.map((result) => (
                    <li key={result.id}>
                        <button
                            type="button"
                            data-test={resultTestId}
                            onClick={() => {
                                onChange(result.id);
                                setQuery(result.title);
                            }}
                            className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-[13px] transition-colors hover:bg-secondary/60"
                        >
                            <Search aria-hidden className="size-3.5 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate">{result.title}</span>
                        </button>
                    </li>
                ))}
                {trimmed !== '' && available.length === 0 && !searching ? (
                    <li className="px-3 py-4 text-center text-sm text-muted-foreground" data-test={emptyTestId}>
                        {t(emptyKey)}
                    </li>
                ) : null}
                {trimmed !== '' && searching ? (
                    <li className="px-3 py-4 text-center text-sm text-muted-foreground" data-test={`${inputTestId}-loading`}>
                        {t(loadingKey)}
                    </li>
                ) : null}
            </ul>
        </div>
    );
}