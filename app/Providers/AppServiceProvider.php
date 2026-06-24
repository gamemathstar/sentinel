<?php

namespace App\Providers;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Authoring\Policies\AssessmentPolicy;
use App\Modules\Certification\Contracts\CertificateAnchor;
use App\Modules\Certification\Listeners\IssueCertificateOnScoreFinalized;
use App\Modules\Certification\Services\LocalLedgerAnchor;
use App\Modules\Delivery\Contracts\AiGrader;
use App\Modules\Delivery\Events\ScoreFinalized;
use App\Modules\Delivery\Events\SittingStarted;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Policies\SittingPolicy;
use App\Modules\Delivery\Services\HeuristicAiGrader;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Notifications\Contracts\NotificationTransport;
use App\Modules\Notifications\Listeners\NotifyCandidateOnScoreFinalized;
use App\Modules\Notifications\Transports\LogTransport;
use App\Modules\Proctoring\Listeners\OpenProctoringSessionOnSittingStarted;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\QuestionBank\Policies\ItemPolicy;
use App\Modules\QuestionBank\Policies\QuestionBankPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request/job; read by BelongsToTenant for isolation.
        $this->app->singleton(TenantContext::class);

        // Shared so its per-request permission cache is reused across policy + Gate::before.
        $this->app->singleton(PermissionResolver::class);

        // AI grading is reached through a contract (docs/02 §4); swap this binding for a
        // real model-backed grader without touching the grading workflow.
        $this->app->bind(
            AiGrader::class,
            HeuristicAiGrader::class,
        );

        // Certificate anchoring contract (docs/04 §7); swap for a real ledger client.
        $this->app->bind(
            CertificateAnchor::class,
            LocalLedgerAnchor::class,
        );

        // Notification delivery contract (docs/02 §4); swap per-channel providers later.
        $this->app->bind(
            NotificationTransport::class,
            LogTransport::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(QuestionBank::class, QuestionBankPolicy::class);
        Gate::policy(Assessment::class, AssessmentPolicy::class);
        Gate::policy(Sitting::class, SittingPolicy::class);

        // Certification + Notifications subscribe to score finalization (docs/01 §5).
        Event::listen(
            ScoreFinalized::class,
            IssueCertificateOnScoreFinalized::class,
        );
        Event::listen(
            ScoreFinalized::class,
            NotifyCandidateOnScoreFinalized::class,
        );

        // Proctoring opens a session when a sitting starts (docs/01 §5).
        Event::listen(
            SittingStarted::class,
            OpenProctoringSessionOnSittingStarted::class,
        );

        // Platform super admins bypass every permission check (docs/04 §5).
        Gate::before(function ($user) {
            if ($user instanceof User && app(PermissionResolver::class)->isPlatformSuperAdmin($user)) {
                return true;
            }

            return null; // defer to policies
        });
    }
}
