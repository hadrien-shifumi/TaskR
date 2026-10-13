<?php

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ShowLoginPage;
use App\Actions\Auth\ShowRegistrationPage;
use App\Actions\Statuses\CreateStatus;
use App\Actions\Statuses\DeleteStatus;
use App\Actions\Statuses\ReorderStatuses;
use App\Actions\Statuses\ShowStatusesPage;
use App\Actions\Statuses\UpdateStatus;
use App\Actions\System\ShowHomeAction;
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
});
