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
    /**
     * The current user's running timer in this workspace, or null.
     * Lazy shared prop: rehydrated on every navigation, so closing the
     * browser never stops the timer — the server timestamp is the truth.
     */
    timer?: TimerState | null;
    /**
     * Members and projects mentionable in comments, within the account's
     * perimeter. Deferred prop: absent until the client's parallel partial
     * reload lands.
     */
    mentionables?: Mentionable[];
}

/** Live state of the user's running timer (shared prop + timer endpoints). */
export interface TimerState {
    id: string;
    issueId: string;
    issueTitle: string;
    /** ISO-8601 start timestamp — the server's clock is the truth. */
    startedAt: string;
    elapsedHours: number;
    exceeds24h: boolean;
}

/**
 * The pre-filled standard-entry proposal produced when a running timer is
 * stopped or replaced: confirming it goes through the ordinary time-entry
 * flow (hours pre-filled, today's date, empty editable note).
 */
export interface StoppedTimerProposal {
    issueId: string;
    issueTitle: string;
    startedAt: string;
    stoppedAt: string;
    elapsedHours: number;
    roundedHours: number;
    exceeds24h: boolean;
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

/**
 * One row of the weekly timesheet grid: the hours summed per day of the
 * week and over the whole week, for a single ticket.
 */
export interface TimesheetGrid {
    issueId: string;
    issueTitle: string;
    /** Hours per day, keyed by `YYYY-MM-DD`. Only filled days appear. */
    days: Record<string, number>;
    weekTotal: number;
}

/** Raw entry of the week, for the list view (edit / delete author-only). */
export interface TimeEntryRow {
    id: string;
    authorId: number;
    authorName: string | null;
    issueId: string;
    issueTitle: string;
    workedAt: string;
    hours: number;
    note: string | null;
}

/** Payload of the "My time" page: the week's grid, total and raw entries. */
export interface WeekTimesheet {
    /** Monday of the displayed week, `YYYY-MM-DD`. */
    weekStart: string;
    /** Sunday of the displayed week, `YYYY-MM-DD`. */
    weekEnd: string;
    weekTotal: number;
    grid: TimesheetGrid[];
    entries: TimeEntryRow[];
}

/** Row of the live issue search feeding the "add a line" dialog. */
export interface IssueSearchResult {
    id: string;
    title: string;
    projectId: string;
}
