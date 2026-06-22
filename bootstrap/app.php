<?php

use App\Modules\Authoring\Exceptions\AssemblyShortfall;
use App\Modules\Authoring\Exceptions\PublishValidationFailed;
use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Identity\Exceptions\InvalidCredentials;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // Failed authentication / MFA -> 401.
        $exceptions->render(fn (InvalidCredentials $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 401)
            : null);
        // Blueprint cannot be satisfied by the bank -> 422 with the per-band shortfall.
        $exceptions->render(fn (AssemblyShortfall $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage(), 'shortfall' => $e->shortfall], 422)
            : null);
        // Assessment not ready to publish -> 422 with reasons.
        $exceptions->render(fn (PublishValidationFailed $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422)
            : null);
        // Delivery rule violation (state/deadline/duplicate) -> 422.
        $exceptions->render(fn (DeliveryError $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);
    })->create();
