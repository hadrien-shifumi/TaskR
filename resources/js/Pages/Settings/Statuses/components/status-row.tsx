import { StatusBadge } from '@/components/issue-badges';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { GripVertical, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { StatusRow } from '../types';

type StatusRowItemProps = {
    status: StatusRow;
    isOwner: boolean;
    dragging: boolean;
    over: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
    onDragOver: (e: React.DragEvent) => void;
    onDrop: (e: React.DragEvent) => void;
    onEdit: () => void;
    onDelete: () => void;
};

/** One draggable status entry of the reorderable list. */
export function StatusRowItem({
    status,
    isOwner,
    dragging,
    over,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDrop,
    onEdit,
    onDelete,
}: StatusRowItemProps) {
    const { t } = useTranslation();
    return (
        <div
            draggable={isOwner}
            data-test={`status-row-${status.name}`}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            onDragOver={onDragOver}
            onDrop={onDrop}
            className={`flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 shadow-xs ${
                status.issuesCount === 0 ? 'border-dashed' : ''
            } ${over && !dragging ? 'border-primary ring-2 ring-ring' : ''} ${dragging ? 'opacity-40' : ''}`}
        >
            {isOwner ? (
                <span aria-hidden="true" className="cursor-grab text-muted-foreground">
                    <GripVertical className="size-4" />
                </span>
            ) : null}
            <strong className="text-sm">{status.name}</strong>
            <StatusBadge name={t(`statusCategory.${status.category}`)} category={status.category} />
            <span className="ml-auto font-mono text-xs text-muted-foreground tabular-nums">
                {status.issuesCount === 1
                    ? t('projectsPage.issueCount', { count: status.issuesCount })
                    : t('projectsPage.issueCountPlural', { count: status.issuesCount })}
            </span>
            {isOwner ? (
                <DropdownMenu>
                    <DropdownMenuTrigger
                        aria-label={t('projectMenu.actionsFor', { name: status.name })}
                        className="grid size-6.5 place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                    >
                        <MoreHorizontal className="size-4" aria-hidden />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>{status.name}</DropdownMenuLabel>
                            <DropdownMenuItem onClick={onEdit}>
                                <Pencil aria-hidden />
                                {t('settings.rename')}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" onClick={onDelete}>
                                <Trash2 aria-hidden />
                                {t('projectMenu.delete')}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </div>
    );
}
