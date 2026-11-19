import { diffLines } from 'diff';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

export interface DiffSide {
    id: string;
    title: string;
    body: string;
    editor: string | null;
    createdAt: string | null;
}

/**
 * Unified line diff between two version bodies: removed lines on a red
 * wash, added lines on a green wash, context lines plain. The title
 * change, when the two versions renamed the page, is shown separately
 * above the body diff.
 */
export function VersionDiff({ from, to }: { from: DiffSide; to: DiffSide }) {
    const { t } = useTranslation();

    const parts = diffLines(from.body, to.body);
    const linesAdded = parts.reduce((total, part) => total + (part.added ? (part.count ?? 0) : 0), 0);
    const linesRemoved = parts.reduce((total, part) => total + (part.removed ? (part.count ?? 0) : 0), 0);

    return (
        <div>
            {from.title !== to.title ? (
                <p className="mb-3 rounded-lg border bg-muted/40 px-3 py-2 text-sm">
                    {t('knowledge.versions.titleChanged', { from: from.title, to: to.title })}
                </p>
            ) : null}
            <div className="mb-3 flex items-center gap-2">
                <Badge lineCount={linesAdded} variant="added" />
                <Badge lineCount={linesRemoved} variant="removed" />
            </div>
            {linesAdded === 0 && linesRemoved === 0 ? (
                <p className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                    {t('knowledge.versions.noChanges')}
                </p>
            ) : (
                <div className="overflow-hidden rounded-lg border font-mono text-xs leading-relaxed">
                    {parts.map((part, partIndex) =>
                        part.value
                            .split('\n')
                            .filter((line, lineIndex, lines) => !(line === '' && lineIndex === lines.length - 1))
                            .map((line, lineIndex) => (
                                <div
                                    key={`${partIndex}-${lineIndex}`}
                                    className={cn(
                                        'flex gap-2 px-3 py-0.5 whitespace-pre-wrap break-words',
                                        part.added && 'bg-success/10',
                                        part.removed && 'bg-destructive/10',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'w-2 shrink-0 select-none',
                                            part.added && 'text-success',
                                            part.removed && 'text-destructive',
                                        )}
                                    >
                                        {part.added ? '+' : part.removed ? '−' : ' '}
                                    </span>
                                    <span className="min-w-0 flex-1">{line === '' ? ' ' : line}</span>
                                </div>
                            )),
                    )}
                </div>
            )}
        </div>
    );
}

function Badge({ lineCount, variant }: { lineCount: number; variant: 'added' | 'removed' }) {
    const { t } = useTranslation();

    if (lineCount === 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-[11px] font-medium tabular-nums',
                variant === 'added' ? 'bg-success/10 text-success' : 'bg-destructive/10 text-destructive',
            )}
        >
            {variant === 'added'
                ? (lineCount > 1
                    ? t('knowledge.versions.linesAddedPlural', { count: lineCount })
                    : t('knowledge.versions.linesAdded', { count: lineCount }))
                : (lineCount > 1
                    ? t('knowledge.versions.linesRemovedPlural', { count: lineCount })
                    : t('knowledge.versions.linesRemoved', { count: lineCount }))}
        </span>
    );
}
