<?php

use App\Exceptions\ProcedureFailed;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaffHasBranch;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordChanged::class,
            'staff.branch' => EnsureStaffHasBranch::class,
        ]);

        // Check status, password, role and branch before route-model binding, so
        // a user without access gets 403 on /admin/branches/999 rather than a 404
        // that reveals whether the record exists.
        foreach ([EnsureUserIsActive::class, EnsurePasswordChanged::class, EnsureUserHasRole::class, EnsureStaffHasBranch::class] as $middlewareClass) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $middlewareClass);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 401/403/404/429 etc. on the API use the { success, message, data } envelope.
        // ValidationException is not an HttpException, so 422s keep Laravel's format.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('Unauthenticated.', 401);
            }
        });

        // A stored procedure refused the operation: its message is meant for the user.
        $exceptions->render(function (ProcedureFailed $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();

                if ($e->getPrevious() instanceof ModelNotFoundException) {
                    return ApiResponse::error('The requested record was not found.', 404);
                }

                return ApiResponse::error($e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'), $status, null, $e->getHeaders());
            }
        });
    })->create();
