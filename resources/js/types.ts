export interface AuthUser {
    id: number;
    name: string;
    email: string;
    locale: string;
}

export interface WorkspaceSummary {
    id: string;
    name: string;
    role: 'owner' | 'member';
}

export interface PageSharedProps {
    [key: string]: unknown;
    auth: { user: AuthUser | null };
    workspace: WorkspaceSummary | null;
    workspaces: WorkspaceSummary[];
    /** Active application locale for the request (en | fr). */
    locale: string;
    /** Unread @mentions of the account in the current workspace. */
    unreadMentions: number;
    /** Members and projects mentionable in comments, within the account's perimeter. */
    mentionables: Mentionable[];
}

/** Autocomplete entry for the comment @mention picker. */
export interface Mentionable {
    handle: string;
    name: string;
    type: 'user' | 'project';
}

/** Minimal user reference embedded in issue payloads. */
export interface UserRef {
    id: string;
    name: string;
}

/** Minimal label reference embedded in issue payloads. */
export interface LabelRef {
    id: string;
    name: string;
    color: string;
}

/** Option row of a milestone select (issue dialogs). */
export interface MilestoneOption {
    id: string;
    title: string;
}

/**
 * Teaser of the milestone attached to an issue, shown on the issue
 * fiche: the dates and the late flag render without a round trip.
 */
export interface MilestoneSummary {
    id: string;
    title: string;
    startDate: string | null;
    targetDate: string | null;
    completedAt: string | null;
    overdue: boolean;
}

/**
 * Milestone row of a project's Échéances tab and detail page: the
 * summary fields plus the parent id (the client rebuilds the tree)
 * and the issue progression computed by the server.
 */
export interface MilestoneCard extends MilestoneSummary {
    description: string | null;
    projectId: string;
    parentId: string | null;
    creatorName: string | null;
    totalIssues: number;
    doneIssues: number;
}

/**
 * Active-descendant summary of an issue: the total and the count per
 * status id. A screen compares it with the target status to decide whether
 * a move must offer to bring the whole sub-tree along.
 */
export interface DescendantsSummary {
    total: number;
    byStatus: Record<string, number>;
}

/** Computed health indicators of a project (always calculated, never stored). */
export interface HealthData {
    issuesByCategory: { backlog: number; in_progress: number; done: number; cancelled: number };
    totalIssues: number;
    completionPercentage: number | null;
    overdueIssues: number;
    estimatedHours: number;
    loggedHours: number;
    consumptionRatio: number | null;
    expectedPercentage: number | null;
    weightedCompletionPercentage: number | null;
    leafCompletionPercentage: number | null;
    status: string | null;
    targetDate: string | null;
}

/** Project row payload shared by the projects list and the project screens. */
export interface ProjectRow {
    id: string;
    name: string;
    description: string | null;
    targetDate: string | null;
    archivedAt: string | null;
    health: HealthData | null;
}

/** Minimal editable project reference used by shared dialogs and menus. */
export type ProjectRef = Pick<ProjectRow, 'id' | 'name' | 'description' | 'targetDate'>;
