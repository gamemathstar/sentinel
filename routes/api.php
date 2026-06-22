<?php

use App\Http\Middleware\Authenticate;
use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use App\Modules\Authoring\Http\Controllers\AssessmentController;
use App\Modules\Authoring\Http\Controllers\BlueprintController;
use App\Modules\Authoring\Http\Controllers\ScoringRuleController;
use App\Modules\Delivery\Http\Controllers\SittingController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\QuestionBank\Http\Controllers\ItemController;
use Illuminate\Support\Facades\Route;

/*
 | Authentication (IAM module). Public endpoints issue/upgrade a bearer token; the rest
 | of the API runs behind the Authenticate middleware, which also sets the tenant context
 | from the authenticated user.
 */
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('mfa/verify', [AuthController::class, 'verifyMfa']);

    Route::middleware(Authenticate::class)->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('mfa/enroll', [AuthController::class, 'enrollMfa']);
        Route::post('mfa/confirm', [AuthController::class, 'confirmMfa']);
    });
});

/*
 | Question Bank API. Authenticated + tenant-scoped; per-action authorization is enforced
 | by ItemPolicy inside the controller.
 */
Route::middleware(Authenticate::class)->prefix('question-bank')->group(function () {
    Route::get('items', [ItemController::class, 'index']);
    Route::post('items', [ItemController::class, 'store']);
    Route::get('items/{id}', [ItemController::class, 'show']);
    Route::post('items/{id}/versions', [ItemController::class, 'storeVersion']);
    Route::post('versions/{versionId}/reviews', [ItemController::class, 'review']);
    Route::post('items/import', [ItemController::class, 'import']);
});

/*
 | Assessment Authoring API. Authenticated + tenant-scoped; AssessmentPolicy and explicit
 | permission checks gate each action.
 */
Route::middleware(Authenticate::class)->prefix('authoring')->group(function () {
    Route::get('assessments', [AssessmentController::class, 'index']);
    Route::post('assessments', [AssessmentController::class, 'store']);
    Route::get('assessments/{id}', [AssessmentController::class, 'show']);
    Route::post('assessments/{id}/sections', [AssessmentController::class, 'addSection']);
    Route::post('assessments/{id}/sections/{sectionId}/assemble', [AssessmentController::class, 'assembleSection']);
    Route::post('assessments/{id}/publish', [AssessmentController::class, 'publish']);

    Route::get('blueprints', [BlueprintController::class, 'index']);
    Route::post('blueprints', [BlueprintController::class, 'store']);

    Route::get('scoring-rules', [ScoringRuleController::class, 'index']);
    Route::post('scoring-rules', [ScoringRuleController::class, 'store']);
});

/*
 | Exam Delivery & Scoring API. Candidates act only on their own sitting; staff assign.
 */
Route::middleware(Authenticate::class)->prefix('delivery')->group(function () {
    Route::post('assessments/{assessmentId}/sittings', [SittingController::class, 'assign']);
    Route::post('sittings/{id}/start', [SittingController::class, 'start']);
    Route::get('sittings/{id}', [SittingController::class, 'show']);
    Route::post('sittings/{id}/responses', [SittingController::class, 'respond']);
    Route::post('sittings/{id}/submit', [SittingController::class, 'submit']);
    Route::get('sittings/{id}/score', [SittingController::class, 'score']);
});

/*
 | Analytics & Psychometrics API. Read models computed off the hot path.
 */
Route::middleware(Authenticate::class)->prefix('analytics')->group(function () {
    Route::post('assessments/{assessmentId}/compile', [AnalyticsController::class, 'compile']);
    Route::get('assessments/{assessmentId}/reliability', [AnalyticsController::class, 'reliability']);
    Route::get('items/{itemId}/statistics', [AnalyticsController::class, 'itemStatistics']);
});
