<?php

use App\Modules\Authoring\Exceptions\AssemblyShortfall;
use App\Modules\Authoring\Exceptions\PublishValidationFailed;
use App\Modules\Certification\Exceptions\CertificationError;
use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Delivery\Exceptions\GradingError;
use App\Modules\Identity\Exceptions\InvalidCredentials;
use App\Modules\Proctoring\Exceptions\ProctoringError;
use App\Modules\Reporting\Exceptions\ReportingError;
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
        // Grading-workflow violation (state / separation of duties) -> 422.
        $exceptions->render(fn (GradingError $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);
        // Certification rule violation (e.g. non-final score) -> 422.
        $exceptions->render(fn (CertificationError $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);
        // Proctoring rule violation (unknown flag type / bad decision) -> 422.
        $exceptions->render(fn (ProctoringError $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);
        // Reporting request violation (unknown type/format) -> 422.
        $exceptions->render(fn (ReportingError $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);
    })->create();
