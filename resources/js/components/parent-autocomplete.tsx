import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ChevronRight, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

/** Row of a parent search endpoint JSON payload. */
export type ParentSearchResult = {
    id: string;
    title: string;
    /** Ancestor chain of the page, root first — drives the hierarchy indent. */
    path?: { id: string; title: string }[];
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
    /**
     * Rows offered while the query is empty (e.g. the tree roots), below
     * the "no parent" option. Without it the empty state shows the
     * "no parent" row only.
     */
    emptyQueryResults?: ParentSearchResult[];
    autoFocus?: boolean;
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
 * One pickable row: indented by the depth of the result's ancestor path,
 * with the path itself as a muted breadcrumb — a deep page reads as
 * "Root › Parent › Title" and visually sinks under its ancestors.
 */
function AutocompleteRow({
    result,
    resultTestId,
    onPick,
}: {
    result: ParentSearchResult;
    resultTestId: string;
    onPick: () => void;
}) {
    const path = result.path ?? [];

    return (
        <li>
            <button
                type="button"
                data-test={resultTestId}
                onClick={onPick}
                className="flex w-full items-center gap-1.5 py-2.5 pr-3 text-left text-[13px] transition-colors hover:bg-secondary/60"
                style={path.length > 0 ? { paddingLeft: `${0.75 + path.length * 0.9}rem` } : undefined}
            >
                <Search aria-hidden className="size-3.5 shrink-0 text-muted-foreground" />
                {path.map((ancestor) => (
                    <span
                        key={ancestor.id}
                        className="hidden min-w-0 items-center gap-1 text-muted-foreground sm:flex"
                    >
                        <span className="max-w-32 truncate">{ancestor.title}</span>
                        <ChevronRight className="size-3 shrink-0 text-muted-foreground/70" aria-hidden />
                    </span>
                ))}
                <span className="min-w-0 flex-1 truncate">{result.title}</span>
            </button>
        </li>
    );
}

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
    emptyQueryResults = [],
    autoFocus = false,
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
                autoFocus={autoFocus}
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
                {trimmed === ''
                    ? emptyQueryResults
                          .filter((result) => !excludeIds.includes(result.id))
                          .map((result) => (
                              <AutocompleteRow
                                  key={result.id}
                                  result={result}
                                  resultTestId={resultTestId}
                                  onPick={() => {
                                      onChange(result.id);
                                      setQuery(result.title);
                                  }}
                              />
                          ))
                    : null}
                {trimmed !== ''
                    ? available.map((result) => (
                          <AutocompleteRow
                              key={result.id}
                              result={result}
                              resultTestId={resultTestId}
                              onPick={() => {
                                  onChange(result.id);
                                  setQuery(result.title);
                              }}
                          />
                      ))
                    : null}
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