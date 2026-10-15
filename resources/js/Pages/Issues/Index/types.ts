import type { LabelRef, UserRef } from '@/types';

export interface IssueRow {
    id: string;
    title: string;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    assignees: UserRef[];
    labels: LabelRef[];
}

export interface TemplateOption {
    id: string;
    name: string;
    description: string | null;
    priority: string | null;
    labels: { id: string; name: string }[];
}
