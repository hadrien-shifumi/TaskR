import type { LabelRef, UserRef } from '@/types';

/** Dependency teaser listed on the fiche (blocker or blocked issue). */
export type DependencyRef = {
    id: string;
    title: string;
    statusName: string | null;
    statusCategory: string | null;
};

/**
 * Row of the "blocks" mirror. Carries the dependent's own blocker ids so
 * a removal from this side can re-sync the dependent minus this issue.
 */
export type BlocksRef = DependencyRef & { blockerIds: string[] };

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
    blockedBy: DependencyRef[];
    blocks: BlocksRef[];
    isBlocked: boolean;
    isUnblockable: boolean;
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

/** Single before → after change of an activity event (null = emptied value). */
export type ActivityChange = {
    before: unknown;
    after: unknown;
};

/**
 * Audit timeline row: journalled event with a dotted type ('issue.created'),
 * a null actor for system events and the changed fields. Descending order
 * is chosen by the backend query. `commentId` links comment events to the
 * comment they are about; `changes` may also carry flat added/removed lists
 * (assignees, knowledge pages), hence the `unknown` value type.
 */
export type ActivityEvent = {
    id: string;
    type: string;
    actor: { id: number; name: string } | null;
    createdAt: string | null;
    commentId: string | null;
    changes: Record<string, ActivityChange | unknown>;
};
