<?php

use App\Actions\Auth\CreateApiToken;
use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ResetPassword;
use App\Actions\Auth\RevokeApiToken;
use App\Actions\Auth\SendPasswordResetLink;
use App\Actions\Auth\ShowAccountSettingsPage;
use App\Actions\Auth\ShowApiTokensPage;
use App\Actions\Auth\ShowForgotPasswordPage;
use App\Actions\Auth\ShowLoginPage;
use App\Actions\Auth\ShowNotificationSettingsPage;
use App\Actions\Auth\ShowRegistrationPage;
use App\Actions\Auth\ShowResetPasswordPage;
use App\Actions\Auth\UpdateNotificationPreferences;
use App\Actions\Auth\UpdatePassword;
use App\Actions\Auth\UpdateUserLocale;
use App\Actions\Comments\AddComment;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\UpdateComment;
use App\Actions\Issues\ArchiveCompletedIssues;
use App\Actions\Issues\ArchiveIssue;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\DeleteIssue;
use App\Actions\Issues\ExportIssues;
use App\Actions\Issues\MoveIssue;
use App\Actions\Issues\SearchIssueKnowledge;
use App\Actions\Issues\SearchIssues;
use App\Actions\Issues\SetAssignees;
use App\Actions\Issues\SetIssueAutoCompletion;
use App\Actions\Issues\SetIssueDependencies;
use App\Actions\Issues\SetIssueKnowledge;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Issues\SetLabels;
use App\Actions\Issues\ShowIssuePage;
use App\Actions\Issues\ShowIssuesPage;
use App\Actions\Issues\UpdateIssue;
use App\Actions\Knowledge\CreateKnowledgePage;
use App\Actions\Knowledge\DeleteKnowledgePage;
use App\Actions\Knowledge\ForceDeleteKnowledgePage;
use App\Actions\Knowledge\GetKnowledgePageVersion;
use App\Actions\Knowledge\RestoreKnowledgePage;
use App\Actions\Knowledge\RestoreKnowledgePageVersion;
use App\Actions\Knowledge\SearchKnowledge;
use App\Actions\Knowledge\ShowKnowledgePage;
use App\Actions\Knowledge\ShowKnowledgeTrash;
use App\Actions\Knowledge\ShowKnowledgeVersions;
use App\Actions\Knowledge\UpdateKnowledgePage;
use App\Actions\Labels\CreateLabel;
use App\Actions\Mentions\MarkAllMentionsRead;
use App\Actions\Mentions\MarkMentionRead;
use App\Actions\Mentions\ShowMentionsPage;
use App\Actions\Milestones\CloseMilestone;
use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\ListMilestones;
use App\Actions\Milestones\SetIssueMilestone;
use App\Actions\Milestones\SetMilestoneParent;
use App\Actions\Milestones\ShowMilestonePage;
use App\Actions\Milestones\ShowMilestonesPage;
use App\Actions\Milestones\UpdateMilestone;
use App\Actions\Projects\AddProjectMember;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\Projects\RemoveProjectMember;
use App\Actions\Projects\ShowProjectActivity;
use App\Actions\Projects\ShowProjectArchives;
use App\Actions\Projects\ShowProjectBoard;
use App\Actions\Projects\ShowProjectMemberActivity;
use App\Actions\Projects\ShowProjectMembers;
use App\Actions\Projects\ShowProjectsPage;
use App\Actions\Projects\UpdateProject;
use App\Actions\Projects\UpdateProjectMemberRole;
use App\Actions\SavedViews\CreateSavedView;
use App\Actions\SavedViews\DeleteSavedView;
use App\Actions\SavedViews\UpdateSavedView;
use App\Actions\Statuses\CreateStatus;
use App\Actions\Statuses\DeleteStatus;
use App\Actions\Statuses\ReorderStatuses;
use App\Actions\Statuses\ShowStatusesPage;
use App\Actions\Statuses\UpdateStatus;
use App\Actions\System\ShowHomeAction;
use App\Actions\Templates\CreateIssueTemplate;
use App\Actions\Templates\DeleteIssueTemplate;
use App\Actions\Templates\ShowTemplatesPage;
use App\Actions\Templates\UpdateIssueTemplate;
use App\Actions\TimeTracking\CreateTimeEntry;
use App\Actions\TimeTracking\DeleteTimeEntry;
use App\Actions\TimeTracking\ExportTimeEntries;
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\TimeTracking\ShowActiveTimers;
use App\Actions\TimeTracking\ShowMyTimePage;
use App\Actions\TimeTracking\ShowWeekTimesheet;
use App\Actions\TimeTracking\StartTimer;
use App\Actions\TimeTracking\StopTimer;
use App\Actions\TimeTracking\StoreWeekTimesheet;
use App\Actions\TimeTracking\UpdateTimeEntry;
use App\Actions\Workspaces\AcceptInvitation;
use App\Actions\Workspaces\CancelInvitation;
use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\DownloadWorkspaceBackup;
use App\Actions\Workspaces\InviteMember;
use App\Actions\Workspaces\RemoveMember;
use App\Actions\Workspaces\ResendInvitation;
use App\Actions\Workspaces\ShowGeneralSettingsPage;
use App\Actions\Workspaces\ShowMembersPage;
use App\Actions\Workspaces\SwitchWorkspace;
use App\Actions\Workspaces\UpdateGeneralSettings;
use App\Actions\Workspaces\UpdateMemberScope;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', ShowLoginPage::class)->name('login');
    Route::post('/login', LoginUser::class)->name('login.attempt');
    Route::get('/register', ShowRegistrationPage::class)->name('register');
    Route::post('/register', RegisterUser::class)->name('register.store');

    Route::get('/forgot-password', ShowForgotPasswordPage::class)->name('password.request');
    Route::post('/forgot-password', SendPasswordResetLink::class)->name('password.email');
    Route::get('/reset-password/{token}', ShowResetPasswordPage::class)->name('password.reset');
    Route::post('/reset-password', ResetPassword::class)->name('password.update');
});

Route::post('/logout', LogoutUser::class)->middleware('auth')->name('logout');

Route::match(['put', 'patch'], '/user/locale', UpdateUserLocale::class)->middleware('auth')->name('user.locale.update');
Route::match(['put', 'patch'], '/user/password', UpdatePassword::class)->middleware('auth')->name('user.password.update');

// Acceptance is reachable by guests: the action renders the invitation landing
// page for them and performs the membership only for authenticated users.
Route::get('/invitations/{invitation}/accept', AcceptInvitation::class)->name('invitations.accept');

Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/', ShowHomeAction::class)->name('home');

    Route::post('/workspaces/{workspace}/switch', SwitchWorkspace::class)->name('workspaces.switch');
    Route::post('/workspaces', CreateWorkspace::class)->name('workspaces.store');

    Route::post('/workspaces/{workspace}/invitations', InviteMember::class)->name('workspaces.invite');
    Route::post('/workspaces/{workspace}/invitations/{invitation}/resend', ResendInvitation::class)->name('workspaces.invitations.resend');
    Route::delete('/workspaces/{workspace}/invitations/{invitation}', CancelInvitation::class)->name('workspaces.invitations.destroy');
    Route::delete('/workspaces/{workspace}/members/{user}', RemoveMember::class)->name('workspaces.members.destroy');
    Route::match(['put', 'patch'], '/workspaces/{workspace}/members/{user}/scope', UpdateMemberScope::class)->name('workspaces.members.scope');

    Route::get('/settings/account', ShowAccountSettingsPage::class)->name('settings.account');

    Route::get('/settings/general', ShowGeneralSettingsPage::class)->name('settings.general');
    Route::match(['put', 'patch'], '/settings/general', UpdateGeneralSettings::class)
        ->name('settings.general.update');

    Route::get('/settings/notifications', ShowNotificationSettingsPage::class)->name('settings.notifications');
    Route::match(['put', 'patch'], '/settings/notifications', UpdateNotificationPreferences::class)
        ->name('settings.notifications.update');

    Route::get('/settings/tokens', ShowApiTokensPage::class)->name('settings.tokens');
    Route::post('/tokens', CreateApiToken::class)->name('tokens.store');
    Route::delete('/tokens/{token}', RevokeApiToken::class)->name('tokens.destroy');

    Route::get('/settings/statuses', ShowStatusesPage::class)->name('settings.statuses');
    Route::get('/settings/members', ShowMembersPage::class)->name('settings.members');
    Route::post('/statuses', CreateStatus::class)->name('statuses.store');
    Route::post('/statuses/reorder', ReorderStatuses::class)->name('statuses.reorder');
    Route::match(['put', 'patch'], '/statuses/{status}', UpdateStatus::class)->name('statuses.update');
    Route::delete('/statuses/{status}', DeleteStatus::class)->name('statuses.destroy');

    Route::get('/settings/templates', ShowTemplatesPage::class)->name('settings.templates');
    Route::post('/templates', CreateIssueTemplate::class)->name('templates.store');
    Route::match(['put', 'patch'], '/templates/{template}', UpdateIssueTemplate::class)->name('templates.update');
    Route::delete('/templates/{template}', DeleteIssueTemplate::class)->name('templates.destroy');

    Route::get('/projects', ShowProjectsPage::class)->name('projects.index');
    Route::post('/projects', CreateProject::class)->name('projects.store');
    Route::get('/projects/{project}/health', GetProjectHealth::class)->name('projects.health');
    Route::get('/projects/{project}/activity', ShowProjectActivity::class)->name('projects.activity');
    Route::get('/projects/{project}/members/{member}/activity', ShowProjectMemberActivity::class)->name('projects.memberActivity');
    Route::get('/projects/{project}/board', ShowProjectBoard::class)->name('projects.board');
    Route::get('/projects/{project}/archives', ShowProjectArchives::class)->name('projects.archives');
    Route::get('/projects/{project}/members', ShowProjectMembers::class)->name('projects.members.index');
    Route::post('/projects/{project}/members', AddProjectMember::class)->name('projects.members.store');
    Route::match(['put', 'patch'], '/projects/{project}/members/{member}', UpdateProjectMemberRole::class)->name('projects.members.update');
    Route::delete('/projects/{project}/members/{member}', RemoveProjectMember::class)->name('projects.members.destroy');
    Route::match(['put', 'patch'], '/projects/{project}', UpdateProject::class)->name('projects.update');
    Route::post('/projects/{project}/archive', ArchiveProject::class)->name('projects.archive');
    Route::post('/projects/{project}/issues/archive-completed', ArchiveCompletedIssues::class)->name('projects.issues.archive-completed');
    Route::delete('/projects/{project}', DeleteProject::class)->name('projects.destroy');

    // Échéances (milestones) — the tab is a project page like Health or
    // Board; the JSON feed stays the option source of the frontend
    // dialogs, one path below the page.
    Route::get('/projects/{project}/milestones', ShowMilestonesPage::class)->name('projects.milestones');
    Route::get('/projects/{project}/milestones/list', ListMilestones::class)->name('milestones.index');
    Route::post('/projects/{project}/milestones', CreateMilestone::class)->name('milestones.store');
    Route::get('/milestones/{milestone}', ShowMilestonePage::class)->name('milestones.show');
    Route::match(['put', 'patch'], '/milestones/{milestone}', UpdateMilestone::class)->name('milestones.update');
    Route::delete('/milestones/{milestone}', DeleteMilestone::class)->name('milestones.destroy');
    Route::post('/milestones/{milestone}/close', CloseMilestone::class)->name('milestones.close');
    Route::post('/milestones/{milestone}/parent', SetMilestoneParent::class)->name('milestones.parent');

    Route::get('/issues', ShowIssuesPage::class)->name('issues.index');
    Route::post('/issues', CreateIssue::class)->name('issues.store');
    // Declared before /issues/{issue} so "export" is not captured as an id.
    Route::get('/issues/export', ExportIssues::class)->name('issues.export');
    // Same guard for the live search: "search" is not a ticket id.
    Route::get('/issues/search', SearchIssues::class)->name('issues.search');
    Route::get('/issues/{issue}', ShowIssuePage::class)->name('issues.show');
    Route::match(['put', 'patch'], '/issues/{issue}', UpdateIssue::class)->name('issues.update');
    Route::delete('/issues/{issue}', DeleteIssue::class)->name('issues.destroy');
    Route::post('/issues/{issue}/move', MoveIssue::class)->name('issues.move');
    Route::post('/issues/{issue}/parent', SetIssueParent::class)->name('issues.parent');
    Route::post('/issues/{issue}/dependencies', SetIssueDependencies::class)->name('issues.dependencies');
    Route::post('/issues/{issue}/assignees', SetAssignees::class)->name('issues.assignees');
    Route::post('/issues/{issue}/labels', SetLabels::class)->name('issues.labels');
    Route::post('/issues/{issue}/auto-complete', SetIssueAutoCompletion::class)->name('issues.auto-complete');
    Route::post('/issues/{issue}/knowledge', SetIssueKnowledge::class)->name('issues.knowledge');
    Route::get('/issues/{issue}/knowledge/search', SearchIssueKnowledge::class)->name('issues.knowledge-search');
    Route::post('/issues/{issue}/milestone', SetIssueMilestone::class)->name('issues.milestone');
    Route::post('/issues/{issue}/archive', ArchiveIssue::class)->name('issues.archive');

    // Saved views of the issues page: a named, workspace-shared snapshot
    // of the URL filters and sort. Renaming is Owner-only; deletion is the
    // Owner's for every view, or the creator's for their own.
    Route::post('/views', CreateSavedView::class)->name('views.store');
    Route::match(['put', 'patch'], '/views/{view}', UpdateSavedView::class)->name('views.update');
    Route::delete('/views/{view}', DeleteSavedView::class)->name('views.destroy');

    Route::post('/issues/{issue}/comments', AddComment::class)->name('comments.store');
    Route::match(['put', 'patch'], '/issues/{issue}/comments/{comment}', UpdateComment::class)
        ->scopeBindings()->name('comments.update');
    Route::delete('/issues/{issue}/comments/{comment}', DeleteComment::class)
        ->scopeBindings()->name('comments.destroy');

    Route::post('/issues/{issue}/estimate', SetIssueEstimate::class)->name('issues.estimate');
    Route::post('/issues/{issue}/time-entries', CreateTimeEntry::class)->name('issues.time-entries.store');
    Route::match(['put', 'patch'], '/issues/{issue}/time-entries/{timeEntry}', UpdateTimeEntry::class)
        ->scopeBindings()->name('issues.time-entries.update');
    Route::delete('/issues/{issue}/time-entries/{timeEntry}', DeleteTimeEntry::class)
        ->scopeBindings()->name('issues.time-entries.destroy');

    // Workspace-wide export of the time entries (billing): transverse to
    // any single issue, so it lives at the workspace level.
    Route::get('/time-entries/export', ExportTimeEntries::class)->name('time-entries.export');

    // Weekly timesheet: the author's week grid (read) and its one-pass
    // validation — one standard entry per filled cell, all-or-nothing.
    Route::get('/time-entries/week', ShowWeekTimesheet::class)->name('time-entries.week.show');
    Route::post('/time-entries/week', StoreWeekTimesheet::class)->name('time-entries.week.store');

    // Personal weekly timesheet page: the same grid, rendered as a page and
    // shareable through the ?week= parameter.
    Route::get('/my-time', ShowMyTimePage::class)->name('my-time.show');

    Route::post('/issues/{issue}/timer/start', StartTimer::class)->name('issues.timer.start');
    Route::post('/timer/stop', StopTimer::class)->name('timer.stop');
    Route::get('/timer', ShowActiveTimers::class)->name('timer.show');

    // Complete workspace backup (owner only): every project, ticket, time
    // entry, comment and knowledge page in one archive — the escape hatch.
    Route::get('/backup', DownloadWorkspaceBackup::class)->name('backup.download');

    Route::get('/mentions', ShowMentionsPage::class)->name('mentions.index');
    Route::post('/mentions/read-all', MarkAllMentionsRead::class)->name('mentions.read-all');
    Route::post('/mentions/{mention}/read', MarkMentionRead::class)->name('mentions.read');

    Route::post('/labels', CreateLabel::class)->name('labels.store');

    Route::get('/knowledge', ShowKnowledgePage::class)->name('knowledge.index');
    Route::get('/knowledge/search', SearchKnowledge::class)->name('knowledge.search');
    Route::post('/knowledge', CreateKnowledgePage::class)->name('knowledge.store');

    // Trash — declared before /knowledge/{page} so "trash" is not captured
    // as a page id. Restore and force-delete use ->withTrashed() so the
    // implicit model binding resolves soft-deleted pages.
    Route::get('/knowledge/trash', ShowKnowledgeTrash::class)->name('knowledge.trash');
    Route::post('/knowledge/{page}/restore', RestoreKnowledgePage::class)
        ->withTrashed()->name('knowledge.restore');
    Route::delete('/knowledge/{page}/force', ForceDeleteKnowledgePage::class)
        ->withTrashed()->name('knowledge.force-delete');

    // Versioning — history page, single-version preview (JSON) and restore.
    Route::get('/knowledge/{page}/versions', ShowKnowledgeVersions::class)->name('knowledge.versions');
    Route::get('/knowledge/{page}/versions/{version}', GetKnowledgePageVersion::class)
        ->scopeBindings()->name('knowledge.versions.show');
    Route::post('/knowledge/{page}/versions/{version}/restore', RestoreKnowledgePageVersion::class)
        ->scopeBindings()->name('knowledge.versions.restore');

    Route::get('/knowledge/{page}', ShowKnowledgePage::class)->name('knowledge.show');
    Route::match(['put', 'patch'], '/knowledge/{page}', UpdateKnowledgePage::class)->name('knowledge.update');
    Route::delete('/knowledge/{page}', DeleteKnowledgePage::class)->name('knowledge.destroy');
});
