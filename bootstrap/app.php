<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sekolah harus diketahui sebelum sesi dan pengguna dibaca (ADR-004)
        $middleware->web(prepend: [ResolveTenant::class]);
        $middleware->web(append: [
            EnsureActiveUser::class,
            \App\Http\Middleware\ConfineGuests::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->dashboardRoute()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Halaman galat bergaya aplikasi untuk 403/404/419/500 (di luar mode debug)
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (! app()->hasDebugModeEnabled() || in_array($status, [403, 404], true)) {
                if (in_array($status, [403, 404, 500, 503], true) && ! $request->expectsJson()) {
                    return Inertia::render('Error', ['status' => $status])
                        ->toResponse($request)
                        ->setStatusCode($status);
                }
            }

            if ($status === 419) {
                return back()->with('error', 'Sesi kedaluwarsa. Silakan coba lagi.');
            }

            return $response;
        });
    })->create();
