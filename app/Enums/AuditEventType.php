<?php

namespace App\Enums;

/**
 * Types of audit journal events.
 *
 * Backed values are dotted snake_case strings (`<subject>.<action>`), stored
 * in the `audit_events.type` column (string(64)). They cover the minimal
 * coverage of the "Journal d'activité (audit trail)" spec: issues, comments,
 * time entries, projects and knowledge pages.
 */
enum AuditEventType: string
{
    case IssueCreated = 'issue.created';
    case IssueUpdated = 'issue.updated';
    case IssueDeleted = 'issue.deleted';
    case IssueStatusChanged = 'issue.status_changed';
    case IssuePriorityChanged = 'issue.priority_changed';
    case IssueAssigned = 'issue.assigned';
    case IssueUnassigned = 'issue.unassigned';
    case IssueDueDateChanged = 'issue.due_date_changed';
    case IssueEstimateChanged = 'issue.estimate_changed';
    case IssueParentSet = 'issue.parent_set';
    case IssueParentRemoved = 'issue.parent_removed';
    case IssueArchived = 'issue.archived';
    case IssueRestored = 'issue.restored';
    case IssueKnowledgeLinked = 'issue.knowledge_linked';
    case IssueKnowledgeUnlinked = 'issue.knowledge_unlinked';

    case CommentCreated = 'comment.created';
    case CommentUpdated = 'comment.updated';
    case CommentDeleted = 'comment.deleted';

    case TimeEntryCreated = 'time_entry.created';
    case TimeEntryUpdated = 'time_entry.updated';
    case TimeEntryDeleted = 'time_entry.deleted';

    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectArchived = 'project.archived';
    case ProjectUnarchived = 'project.unarchived';
    case ProjectDeleted = 'project.deleted';

    case KnowledgePageCreated = 'knowledge_page.created';
    case KnowledgePageUpdated = 'knowledge_page.updated';
    case KnowledgePageDeleted = 'knowledge_page.deleted';
    case KnowledgePageRestored = 'knowledge_page.restored';
}
