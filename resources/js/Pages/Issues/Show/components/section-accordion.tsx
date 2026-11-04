import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';

type SectionAccordionProps = {
    title: ReactNode;
    /** Mono counter rendered next to the title (same look as SectionHead). */
    count?: ReactNode;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** data-test of the full-width header button. */
    triggerTestId: string;
    /** data-test of the collapsible content panel. */
    panelTestId: string;
    /** Accessible name of the disclosure button. */
    ariaLabel: string;
    children: ReactNode;
};

/**
 * Collapsible section of the issue fiche: the header keeps the SectionHead
 * look (15px title, mono counter) inside a full-width button with a chevron,
 * and the panel renders the section body only while open (folded by default
 * to keep the fiche compact). The h2 wraps the button so the document
 * outline keeps its heading.
 */
export function SectionAccordion({
    title,
    count,
    open,
    onOpenChange,
    triggerTestId,
    panelTestId,
    ariaLabel,
    children,
}: SectionAccordionProps) {
    return (
        <Collapsible open={open} onOpenChange={onOpenChange}>
            <h2 className="mb-3">
                <CollapsibleTrigger
                    data-test={triggerTestId}
                    aria-label={ariaLabel}
                    className="flex w-full items-center gap-2.5 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                >
                    {open ? (
                        <ChevronDown aria-hidden className="size-3.5 shrink-0 text-muted-foreground" />
                    ) : (
                        <ChevronRight aria-hidden className="size-3.5 shrink-0 text-muted-foreground" />
                    )}
                    <span className="text-[15px] font-semibold">{title}</span>
                    {count !== undefined ? (
                        <span className="font-mono text-xs text-muted-foreground tabular-nums">{count}</span>
                    ) : null}
                </CollapsibleTrigger>
            </h2>
            <CollapsiblePanel data-test={panelTestId}>{children}</CollapsiblePanel>
        </Collapsible>
    );
}
