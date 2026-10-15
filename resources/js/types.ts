export interface AuthUser {
    id: number;
    name: string;
    email: string;
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
