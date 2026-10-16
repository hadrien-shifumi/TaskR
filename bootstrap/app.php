<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetLocale must run AFTER the session middleware (it reads the
        // authenticated user's persisted locale from the session), so it is
        // appended — not prepended — immediately before the Inertia share.
        // Prepended, it ran before StartSession: $request->user() was null on
        // every fresh process and the locale fell back to the app default,
        // making the client re-sync (blink + revert to English) on switch.
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            ResolveCurrentWorkspace::class,
        ]);

        $middleware->alias([
            'workspace' => ResolveCurrentWorkspace::class,
        ]);

        // Resolve the current workspace before route model binding, otherwise
        // the WorkspaceScope would filter scoped models out during binding.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveCurrentWorkspace::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
