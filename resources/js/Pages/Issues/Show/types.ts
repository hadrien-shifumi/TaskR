import type { LabelRef, UserRef } from '@/types';

export type IssueDetail = {
    id: string;
    title: string;
    description: string | null;
    createdAt: string | null;
    creatorName: string | null;
    project: { id: string; name: string };
    status: { id: string; name: string; category: string };
    priority: string;
    dueDate: string | null;
    overdue: boolean;
    estimatedHours: number | null;
    loggedHours: number;
    remainingHours: number | null;
    isArchived: boolean;
    archivedAt: string | null;
    assignees: UserRef[];
    labels: LabelRef[];
    parent: { id: string; title: string; statusName: string | null } | null;
    children: {
        id: string;
        title: string;
        statusName: string | null;
        assignees: UserRef[];
    }[];
};

export type TimeEntry = {
    id: string;
    authorId: number;
    authorName: string;
    workedAt: string | null;
    hours: number;
    note: string | null;
};

export type CommentItem = {
    id: string;
    body: string;
    authorId: number;
    authorName: string;
    createdAt: string | null;
};
