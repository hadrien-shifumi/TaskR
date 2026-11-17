import {
    ParentAutocomplete,
    type ParentSearchResult,
} from '@/components/parent-autocomplete';
import { useEffect, useState } from 'react';

type KnowledgeParentPickerProps = {
    /** Selected parent id; '' means "no parent". */
    value: string;
    onChange: (id: string) => void;
    /** Title of the currently attached parent — shown in the input until edited. */
    currentTitle?: string | null;
    /**
     * The page being edited: the server removes it and its whole subtree
     * from the candidates, so a hierarchy cycle can never be selected.
     */
    excludeId?: string;
    /** Id of the underlying input. */
    inputId: string;
    autoFocus?: boolean;
};

/**
 * Parent picker of the knowledge pages: a live, searchable combobox over
 * the whole workspace (the reader's perimeter — independent of the
 * sidebar's project filter). Results carry their ancestor path so the
 * hierarchy is visible; with no query typed the tree roots are offered.
 */
export function KnowledgeParentPicker({
    value,
    onChange,
    currentTitle,
    excludeId,
    inputId,
    autoFocus = false,
}: KnowledgeParentPickerProps) {
    const [roots, setRoots] = useState<ParentSearchResult[]>([]);

    // Browse-the-top: while the query is empty the combobox offers the
    // root pages so the hierarchy is reachable without typing. The page
    // being edited is excluded server-side, like any search — a root
    // page editing itself would otherwise be proposed as its own parent.
    useEffect(() => {
        let cancelled = false;

        fetch(
            route('knowledge.parents', {
                q: '',
                limit: 25,
                ...(excludeId ? { exclude: excludeId } : {}),
            }),
            { headers: { Accept: 'application/json' } },
        )
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: ParentSearchResult[]) => {
                if (!cancelled) {
                    setRoots(payload);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setRoots([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    const search = (query: string): Promise<ParentSearchResult[]> =>
        fetch(
            route('knowledge.parents', {
                q: query,
                limit: 25,
                ...(excludeId ? { exclude: excludeId } : {}),
            }),
            { headers: { Accept: 'application/json' } },
        )
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((payload: ParentSearchResult[]) => payload)
            .catch(() => []);

    return (
        <ParentAutocomplete
            value={value || 'none'}
            onChange={(id) => onChange(id === 'none' ? '' : id)}
            currentTitle={currentTitle}
            search={search}
            // Belt and braces: the server already excludes the edited
            // page and its subtree; the client filter also drops the
            // page itself from the empty-query roots.
            excludeIds={excludeId ? [excludeId] : []}
            emptyQueryResults={roots}
            labelKey="knowledge.parentPage"
            placeholderKey="knowledge.parentSearchPlaceholder"
            emptyKey="knowledge.parentSearchEmpty"
            loadingKey="common.loading"
            noParentKey="issueShow.noParent"
            inputId={inputId}
            inputTestId="knowledge-parent-search"
            resultsTestId="knowledge-parent-results"
            resultTestId="knowledge-parent-result"
            emptyTestId="knowledge-parent-empty"
            noneTestId="knowledge-parent-none"
            autoFocus={autoFocus}
        />
    );
}
