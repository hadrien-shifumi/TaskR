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
