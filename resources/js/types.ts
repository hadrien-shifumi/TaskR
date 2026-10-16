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
