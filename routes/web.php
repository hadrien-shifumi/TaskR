<?php

use App\Actions\System\ShowHomeAction;
use Illuminate\Support\Facades\Route;

Route::get('/', ShowHomeAction::class)->name('home');
