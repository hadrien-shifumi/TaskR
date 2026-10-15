import { StatusBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { GripVertical } from 'lucide-react';
import type { StatusRow } from '../types';

type StatusRowItemProps = {
    status: StatusRow;
    total: number;
    isOwner: boolean;
    dragging: boolean;
    over: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
    onDragOver: (e: React.DragEvent) => void;
    onDrop: (e: React.DragEvent) => void;
    onMove: (delta: number) => void;
    onEdit: () => void;
    onDelete: () => void;
};

/** One draggable status entry of the reorderable list. */
export function StatusRowItem({
    status,
    total,
    isOwner,
    dragging,
    over,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDrop,
    onMove,
    onEdit,
    onDelete,
}: StatusRowItemProps) {
    return (
        <div
            draggable={isOwner}
            data-test={`status-row-${status.name}`}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            onDragOver={onDragOver}
            onDrop={onDrop}
            className={`flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 shadow-xs ${
                over && !dragging ? 'border-primary ring-2 ring-ring' : ''
            } ${dragging ? 'opacity-40' : ''}`}
        >
            {isOwner ? (
                <span aria-label="Reorder" className="cursor-grab text-muted-foreground">
                    <GripVertical className="size-4" />
                </span>
            ) : null}
            <strong className="text-sm">{status.name}</strong>
            <StatusBadge name={status.category} category={status.category} />
            <span className="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
                {status.issuesCount} {status.issuesCount === 1 ? 'issue' : 'issues'}
            </span>
            {isOwner ? (
                <div className="flex gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={status.position === 0}
                        onClick={() => onMove(-1)}
                    >
                        Up
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={status.position === total - 1}
                        onClick={() => onMove(1)}
                    >
                        Down
                    </Button>
                    <Button variant="ghost" size="sm" onClick={onEdit}>
                        Edit
                    </Button>
                    <Button variant="ghost" size="sm" onClick={onDelete}>
                        Delete
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
