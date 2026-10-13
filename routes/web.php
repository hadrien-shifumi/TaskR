<?php

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ShowLoginPage;
use App\Actions\Auth\ShowRegistrationPage;
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
});
