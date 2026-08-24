<?php

use App\Http\Middleware\EnsureAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->trimStrings(except: ['confirmation_name']);

        $middleware->alias([
            'auth.local' => EnsureAuthenticated::class,
        ]);
    })
    ->withExceptions()
    ->create();
