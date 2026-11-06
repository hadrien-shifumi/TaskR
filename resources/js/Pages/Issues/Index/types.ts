import type { DescendantsSummary, LabelRef, UserRef } from '@/types';

export interface IssueRow {
    id: string;
    title: string;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    blocked: boolean;
    unblockable: boolean;
    hasOpenChild: boolean;
    descendants: DescendantsSummary;
    assignees: UserRef[];
    labels: LabelRef[];
    knowledgePagesCount: number;
    knowledgePageTitles: string[];
}

export interface TemplateOption {
    id: string;
    name: string;
    description: string | null;
    priority: string | null;
    labels: { id: string; name: string }[];
}

export interface SavedViewOption {
    id: string;
    name: string;
    summary: string | null;
    /** Stored filter definition — the URL params the view applies. */
    filters: Record<string, string | string[]>;
    sort: string | null;
    dir: string | null;
    /** Creator's user id — drives the "my view" delete permission. */
    createdBy: number | null;
}

export interface KanbanIssue {
    id: string;
    title: string;
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    blocked: boolean;
    unblockable: boolean;
    hasOpenChild: boolean;
    descendants: DescendantsSummary;
    assignees: UserRef[];
    labels: LabelRef[];
    knowledgePagesCount: number;
    knowledgePageTitles: string[];
}

export interface KanbanColumn {
    id: string;
    name: string;
    category: string;
    issuesCount: number;
    issues: KanbanIssue[];
}

export interface ProjectBoard {
    project: { id: string; name: string };
    columns: KanbanColumn[];
}
