<?php

use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Editing snapshots must preserve unfinished strings exactly, including blanks and spaces.
        $isDraft = fn (Request $request) => $request->is('api/form-drafts*') || $request->is('api/vehicle-drafts*');
        $middleware->trimStrings(except: [$isDraft]);
        $middleware->convertEmptyStringsToNull(except: [$isDraft]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->alias([
            'lena.guest' => \App\Http\Middleware\RestrictLenaGuest::class,
            'role' => EnsureRole::class,
            'feature' => EnsureFeatureAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->report(function (\Throwable $e) {
            if (\App\Support\DatabaseBusy::matches($e)) {
                // Never use a database-backed logger or include SQL/token values here.
                error_log('Freightbook DATABASE_BUSY: MySQL connection limit reached (1040).');
                return false;
            }
        });
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (\App\Support\DatabaseBusy::matches($e) && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json([
                    'code' => 'DATABASE_BUSY',
                    'message' => 'The database is busy. Please try again shortly.',
                ], 503, ['Retry-After' => '5', 'Cache-Control' => 'no-store']);
            }
        });
    })->create();
