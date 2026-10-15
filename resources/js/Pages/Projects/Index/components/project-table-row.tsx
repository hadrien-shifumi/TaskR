import { HealthBadge } from '@/components/issue-badges';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/react';
import { Archive, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import type { ProjectRow } from '@/types';

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

type ProjectTableRowProps = {
    project: ProjectRow;
    onEdit: () => void;
    /** Opens the archive confirmation dialog (maquette dlg-archive). */
    onArchive: () => void;
    onDelete: () => void;
};

/** Active project table row: computed health, completion and target date. */
export function ProjectTableRow({ project, onEdit, onArchive, onDelete }: ProjectTableRowProps) {
    const completion = project.health?.completionPercentage ?? null;
    const overdueTarget =
        project.targetDate !== null && new Date(project.targetDate).getTime() < Date.now();

    return (
        <TableRow>
            <TableCell>
                <Link
                    href={route('projects.health', project.id)}
                    className="font-medium hover:text-primary"
                >
                    {project.name}
                </Link>
                {project.description ? (
                    <span className="mt-0.5 block text-xs text-muted-foreground">{project.description}</span>
                ) : null}
            </TableCell>
            <TableCell>{project.health ? <HealthBadge status={project.health.status} /> : null}</TableCell>
            <TableCell>
                <span className="flex items-center gap-2">
                    <span className="h-1.5 w-17.5 overflow-hidden rounded-full bg-secondary">
                        <span
                            className="block h-full rounded-full bg-success"
                            style={{ width: `${completion ?? 0}%` }}
                        />
                    </span>
                    <span className="font-mono text-xs text-muted-foreground tabular-nums">
                        {completion !== null ? `${completion}%` : '—'}
                    </span>
                </span>
            </TableCell>
            <TableCell
                className={`font-mono text-xs tabular-nums ${
                    overdueTarget ? 'font-medium text-destructive' : 'text-muted-foreground'
                }`}
            >
                {formatDate(project.targetDate)}
            </TableCell>
            <TableCell className="font-mono text-xs tabular-nums">
                {project.health?.totalIssues ?? 0}
            </TableCell>
            <TableCell>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        aria-label={`Actions for ${project.name}`}
                        className="grid size-6.5 place-items-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground"
                    >
                        <MoreHorizontal className="size-4" aria-hidden />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuGroup>
                            <DropdownMenuLabel>{project.name}</DropdownMenuLabel>
                            <DropdownMenuItem onClick={onEdit}>
                                <Pencil aria-hidden />
                                Edit…
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={onArchive}>
                                <Archive aria-hidden />
                                Archive…
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" onClick={onDelete}>
                                <Trash2 aria-hidden />
                                Delete…
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}
