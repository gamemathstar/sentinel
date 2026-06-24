<?php

use App\Http\Middleware\Authenticate;
use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use App\Modules\Authoring\Http\Controllers\AssessmentController;
use App\Modules\Authoring\Http\Controllers\BlueprintController;
use App\Modules\Authoring\Http\Controllers\ScoringRuleController;
use App\Modules\Certification\Http\Controllers\CertificateController;
use App\Modules\Delivery\Http\Controllers\GradingController;
use App\Modules\Delivery\Http\Controllers\SittingController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\UserController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Proctoring\Http\Controllers\ProctoringController;
use App\Modules\QuestionBank\Http\Controllers\BankController;
use App\Modules\QuestionBank\Http\Controllers\ItemController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Scheduling\Http\Controllers\ScheduleController;
use App\Modules\Scheduling\Http\Controllers\VenueController;
use App\Modules\Tenancy\Http\Controllers\OrgNodeController;
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
    // Banks (containers) + sharing + staff groups.
    Route::get('banks', [BankController::class, 'index']);
    Route::post('banks', [BankController::class, 'store']);
    Route::get('banks/{id}', [BankController::class, 'show']);
    Route::post('banks/{id}/share-user', [BankController::class, 'shareUser']);
    Route::post('banks/{id}/share-group', [BankController::class, 'shareGroup']);
    Route::delete('banks/{id}/share-user/{userId}', [BankController::class, 'unshareUser']);
    Route::delete('banks/{id}/share-group/{groupId}', [BankController::class, 'unshareGroup']);
    Route::get('groups', [BankController::class, 'groups']);
    Route::post('groups', [BankController::class, 'createGroup']);

    Route::get('items', [ItemController::class, 'index']);
    Route::post('items', [ItemController::class, 'store']);
    Route::get('items/{id}', [ItemController::class, 'show']);
    Route::post('items/{id}/versions', [ItemController::class, 'storeVersion']);
    Route::post('versions/{versionId}/reviews', [ItemController::class, 'review']);
    Route::post('items/import', [ItemController::class, 'import']);
});

/*
 | Tenant org hierarchy (read) — for course / specialization / bank-owner pickers.
 */
Route::middleware(Authenticate::class)->get('tenancy/org-nodes', [OrgNodeController::class, 'index']);

/* Tenant staff list — for the share-with-user picker. */
Route::middleware(Authenticate::class)->get('iam/users', [UserController::class, 'index']);

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
    Route::post('assessments/{id}/sections/{sectionId}/items', [AssessmentController::class, 'pinItems']);
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
    Route::get('assessments/{assessmentId}/monitor', [SittingController::class, 'monitor']);
    Route::get('sittings/{id}/detail', [SittingController::class, 'detail']);
    Route::post('sittings/{id}/start', [SittingController::class, 'start']);
    Route::post('sittings/{id}/resume', [SittingController::class, 'resume']);
    Route::post('sittings/{id}/extend', [SittingController::class, 'extend']);
    Route::get('sittings/{id}', [SittingController::class, 'show']);
    Route::post('sittings/{id}/responses', [SittingController::class, 'respond']);
    Route::post('sittings/{id}/submit', [SittingController::class, 'submit']);
    Route::get('sittings/{id}/score', [SittingController::class, 'score']);

    // Grading queue for open-ended items (double-marking + reconciliation + AI assist).
    Route::get('grading/tasks', [GradingController::class, 'index']);
    Route::get('grading/tasks/{id}', [GradingController::class, 'show']);
    Route::post('grading/tasks/{id}/ai-suggest', [GradingController::class, 'aiSuggest']);
    Route::post('grading/tasks/{id}/marks', [GradingController::class, 'mark']);
    Route::post('grading/tasks/{id}/reconcile', [GradingController::class, 'reconcile']);
});

/*
 | Scheduling & Timetabling API. Selections resolve students from the org hierarchy;
 | sessions place them in venues/times (manual or auto-mapped); release creates sittings.
 */
Route::middleware(Authenticate::class)->prefix('scheduling')->group(function () {
    Route::get('venues', [VenueController::class, 'index']);
    Route::post('venues', [VenueController::class, 'store']);

    Route::post('selection/preview', [ScheduleController::class, 'previewSelection']);
    Route::post('selection/students', [ScheduleController::class, 'students']);

    Route::get('assessments/{assessmentId}/sessions', [ScheduleController::class, 'sessions']);
    Route::post('assessments/{assessmentId}/sessions', [ScheduleController::class, 'createSession']);
    Route::post('assessments/{assessmentId}/auto-map', [ScheduleController::class, 'autoMap']);
    Route::get('assessments/{assessmentId}/roster', [ScheduleController::class, 'roster']);
    Route::post('assessments/{assessmentId}/release', [ScheduleController::class, 'release']);

    Route::post('sessions/{sessionId}/candidates', [ScheduleController::class, 'assignCandidates']);
    Route::post('sessions/{sessionId}/invigilators', [ScheduleController::class, 'assignInvigilators']);
});

/*
 | Analytics & Psychometrics API. Read models computed off the hot path.
 */
Route::middleware(Authenticate::class)->prefix('analytics')->group(function () {
    Route::post('assessments/{assessmentId}/compile', [AnalyticsController::class, 'compile']);
    Route::get('assessments/{assessmentId}/reliability', [AnalyticsController::class, 'reliability']);
    Route::get('assessments/{assessmentId}/items', [AnalyticsController::class, 'items']);
    Route::get('items/{itemId}/statistics', [AnalyticsController::class, 'itemStatistics']);
});

/*
 | Certification. Verification is PUBLIC (the token is the credential); issuing,
 | listing, and revoking are authenticated + permissioned.
 */
Route::get('certification/verify/{token}', [CertificateController::class, 'verify']);

/*
 | Proctoring. Monitors ingest flags/evidence and compute explainable risk; QA reviews.
 | A high risk routes to review — it never auto-voids a sitting (docs/05 §1).
 */
Route::middleware(Authenticate::class)->prefix('proctoring')->group(function () {
    Route::post('sittings/{sittingId}/session', [ProctoringController::class, 'openSession']);
    Route::post('sessions/{sessionId}/flags', [ProctoringController::class, 'recordFlag']);
    Route::post('sessions/{sessionId}/assess', [ProctoringController::class, 'assess']);
    Route::get('sessions/{sessionId}', [ProctoringController::class, 'show']);
    Route::get('review-queue', [ProctoringController::class, 'reviewQueue']);
    Route::post('risk/{riskId}/review', [ProctoringController::class, 'review']);
});

Route::middleware(Authenticate::class)->prefix('certification')->group(function () {
    Route::post('sittings/{sittingId}/issue', [CertificateController::class, 'issue']);
    Route::get('certificates', [CertificateController::class, 'index']);
    Route::post('certificates/{id}/revoke', [CertificateController::class, 'revoke']);
});

/*
 | Notifications. Recipients read their own; senders dispatch (idempotent).
 */
Route::middleware(Authenticate::class)->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('/', [NotificationController::class, 'send']);
});

/*
 | Reporting. Generate PDF/Excel/CSV artifacts from read models, then download.
 */
Route::middleware(Authenticate::class)->prefix('reporting')->group(function () {
    Route::post('reports', [ReportController::class, 'generate']);
    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/{id}', [ReportController::class, 'show']);
    Route::get('reports/{id}/download', [ReportController::class, 'download']);
});
