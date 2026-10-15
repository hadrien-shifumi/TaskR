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

export interface ProjectRow {
    id: string;
    name: string;
    description: string | null;
    targetDate: string | null;
    archivedAt: string | null;
    health: HealthData | null;
}
