import { HealthBadge } from '@/components/issue-badges';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import type { ProjectRow } from '../types';

function formatDate(date: string | null): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

type ProjectListItemProps = {
    project: ProjectRow;
    onEdit: (project: ProjectRow) => void;
};

/** Active project row with health summary and its actions. */
export function ProjectListItem({ project, onEdit }: ProjectListItemProps) {
    const toggleArchive = () => {
        router.post(route('projects.archive', project.id));
    };

    return (
        <li className="flex items-center gap-4 px-4 py-3">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium">{project.name}</p>
                    {project.health ? <HealthBadge status={project.health.status} /> : null}
                    {project.health && project.health.overdueIssues > 0 ? (
                        <span className="font-mono text-xs text-destructive tabular-nums">
                            {project.health.overdueIssues} overdue
                        </span>
                    ) : null}
                </div>
                {project.description ? (
                    <p className="truncate text-sm text-muted-foreground">{project.description}</p>
                ) : null}
                <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                    <span className="font-mono tabular-nums">
                        {project.health?.totalIssues ?? 0} issues
                    </span>
                    {project.targetDate ? <span>target: {formatDate(project.targetDate)}</span> : null}
                    {project.health && project.health.completionPercentage !== null ? (
                        <span className="font-mono tabular-nums">
                            {project.health.completionPercentage}% complete
                        </span>
                    ) : null}
                </div>
            </div>
            <div className="flex shrink-0 gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => router.get(route('projects.health', project.id))}
                >
                    Health
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => router.get(route('projects.board', project.id))}
                >
                    Board
                </Button>
                <Button variant="ghost" size="sm" onClick={() => onEdit(project)}>
                    Edit
                </Button>
                <Button variant="ghost" size="sm" onClick={toggleArchive}>
                    Archive
                </Button>
            </div>
        </li>
    );
}
