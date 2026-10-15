import type { ReactNode } from 'react';

type SectionHeadProps = {
    title: ReactNode;
    /** Mono counter rendered next to the title (maquette .section-head .count). */
    count?: ReactNode;
    /** Action pinned to the right end of the head row. */
    action?: ReactNode;
};

/** Maquette .section-head: 15px title, mono count, action on the right. */
export function SectionHead({ title, count, action }: SectionHeadProps) {
    return (
        <div className="mb-3 flex items-center gap-2.5">
            <h2 className="text-[15px] font-semibold">{title}</h2>
            {count !== undefined ? (
                <span className="font-mono text-xs text-muted-foreground tabular-nums">{count}</span>
            ) : null}
            {action ? (
                <>
                    <span className="flex-1" />
                    {action}
                </>
            ) : null}
        </div>
    );
}
