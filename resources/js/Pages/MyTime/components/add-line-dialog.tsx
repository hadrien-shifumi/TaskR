import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { IssueSearchResult } from '@/types';

type AddLineDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Called with the picked ticket; the dialog closes itself. */
    onPick: (issue: IssueSearchResult) => void;
    /** Tickets already on the grid stay out of the results. */
    excludeIds: string[];
};

/**
 * Live issue search feeding the "add a line" flow. The search endpoint
 * shares the issues page's query — a guest only ever finds the tickets
 * of its perimeter. Results are debounced; empty queries clear the list.
 */
export function AddLineDialog({ open, onClose, onPick, excludeIds }: AddLineDialogProps) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<IssueSearchResult[]>([]);
    const [searching, setSearching] = useState(false);
    const requestId = useRef(0);

    useEffect(() => {
        if (!open) {
            return;
        }

        const trimmed = query.trim();
        if (trimmed === '') {
            setResults([]);
            setSearching(false);
            return;
        }

        setSearching(true);
        const id = ++requestId.current;
        const timer = setTimeout(() => {
            fetch(route('issues.search', { q: trimmed, limit: 10 }), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
                .then((payload: IssueSearchResult[]) => {
                    if (requestId.current !== id) return;
                    setResults(payload);
                })
                .catch(() => {
                    if (requestId.current !== id) return;
                    setResults([]);
                })
                .finally(() => {
                    if (requestId.current === id) setSearching(false);
                });
        }, 300);

        return () => clearTimeout(timer);
    }, [query, open]);

    const available = results.filter((issue) => !excludeIds.includes(issue.id));

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('myTime.addLine')}</DialogTitle>
                    <DialogDescription>{t('myTime.searchHint')}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <label htmlFor="issue-search" className="text-sm font-medium">
                        {t('myTime.searchLabel')}
                    </label>
                    <Input
                        id="issue-search"
                        name="q"
                        autoComplete="off"
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('myTime.searchPlaceholder')}
                        data-test="issue-search-input"
                    />
                </div>
                <ul className="max-h-72 divide-y overflow-y-auto rounded-lg border" data-test="issue-search-results">
                    {available.map((issue) => (
                        <li key={issue.id}>
                            <button
                                type="button"
                                data-test="issue-search-result"
                                onClick={() => {
                                    onPick(issue);
                                    onClose();
                                }}
                                className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-[13px] transition-colors hover:bg-secondary/60"
                            >
                                <Search aria-hidden className="size-3.5 shrink-0 text-muted-foreground" />
                                <span className="min-w-0 flex-1 truncate">{issue.title}</span>
                            </button>
                        </li>
                    ))}
                    {available.length === 0 && query.trim() !== '' && !searching ? (
                        <li className="px-3 py-4 text-center text-sm text-muted-foreground" data-test="issue-search-empty">
                            {t('myTime.searchEmpty')}
                        </li>
                    ) : null}
                    {searching && query.trim() !== '' ? (
                        <li className="px-3 py-4 text-center text-sm text-muted-foreground">
                            {t('common.loading')}
                        </li>
                    ) : null}
                </ul>
                <div className="flex justify-end">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        {t('common.close')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
