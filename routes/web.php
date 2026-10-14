<?php

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ShowLoginPage;
use App\Actions\Auth\ShowRegistrationPage;
use App\Actions\Comments\AddComment;
use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\UpdateComment;
use App\Actions\Issues\CreateIssue;
use App\Actions\Issues\DeleteIssue;
use App\Actions\Issues\MoveIssue;
use App\Actions\Issues\SetAssignees;
use App\Actions\Issues\SetIssueParent;
use App\Actions\Issues\SetLabels;
use App\Actions\Issues\ShowIssuePage;
use App\Actions\Issues\ShowIssuesPage;
use App\Actions\Issues\UpdateIssue;
use App\Actions\Labels\CreateLabel;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\GetProjectHealth;
use App\Actions\Projects\ShowProjectsPage;
use App\Actions\Projects\UpdateProject;
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
use App\Actions\TimeTracking\SetIssueEstimate;
use App\Actions\TimeTracking\UpdateTimeEntry;
use App\Actions\Workspaces\AcceptInvitation;
use App\Actions\Workspaces\InviteMember;
use App\Actions\Workspaces\SwitchWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', ShowLoginPage::class)->name('login');
    Route::post('/login', LoginUser::class)->name('login.attempt');
    Route::get('/register', ShowRegistrationPage::class)->name('register');
    Route::post('/register', RegisterUser::class)->name('register.store');
});

Route::post('/logout', LogoutUser::class)->middleware('auth')->name('logout');

Route::middleware(['auth', 'workspace'])->group(function (): void {
    Route::get('/', ShowHomeAction::class)->name('home');

    Route::post('/workspaces/{workspace}/switch', SwitchWorkspace::class)->name('workspaces.switch');

    Route::post('/workspaces/{workspace}/invitations', InviteMember::class)->name('workspaces.invite');

    Route::get('/invitations/{invitation}/accept', AcceptInvitation::class)->name('invitations.accept');

    Route::get('/settings/statuses', ShowStatusesPage::class)->name('settings.statuses');
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
    Route::match(['put', 'patch'], '/projects/{project}', UpdateProject::class)->name('projects.update');
    Route::post('/projects/{project}/archive', ArchiveProject::class)->name('projects.archive');
    Route::delete('/projects/{project}', DeleteProject::class)->name('projects.destroy');

    Route::get('/issues', ShowIssuesPage::class)->name('issues.index');
    Route::post('/issues', CreateIssue::class)->name('issues.store');
    Route::get('/issues/{issue}', ShowIssuePage::class)->name('issues.show');
    Route::match(['put', 'patch'], '/issues/{issue}', UpdateIssue::class)->name('issues.update');
    Route::delete('/issues/{issue}', DeleteIssue::class)->name('issues.destroy');
    Route::post('/issues/{issue}/move', MoveIssue::class)->name('issues.move');
    Route::post('/issues/{issue}/parent', SetIssueParent::class)->name('issues.parent');
    Route::post('/issues/{issue}/assignees', SetAssignees::class)->name('issues.assignees');
    Route::post('/issues/{issue}/labels', SetLabels::class)->name('issues.labels');

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

    Route::post('/labels', CreateLabel::class)->name('labels.store');
});
