<?php

use App\Exceptions\DocumentStateException;
use App\Exceptions\FiscalNumberingException;
use App\Exceptions\GmailNotConnectedException;
use App\Exceptions\ImmutableDocumentException;
use App\Exceptions\InventoryException;
use App\Exceptions\MissingExchangeRateException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->prepend(AssignRequestId::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (RuntimeException $e, Request $request) {
            $flashable = [DocumentStateException::class, InventoryException::class, FiscalNumberingException::class, ImmutableDocumentException::class, GmailNotConnectedException::class, MissingExchangeRateException::class];
            foreach ($flashable as $class) {
                if ($e instanceof $class && ! $request->expectsJson()) {
                    return back()->with('error', $e->getMessage());
                }
            }

            return null;
        });
    })->create();
