<?php

namespace App\Providers;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Authoring\Policies\AssessmentPolicy;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Policies\SittingPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Policies\ItemPolicy;
use App\Support\Tenancy\TenantContext;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(Assessment::class, AssessmentPolicy::class);
        Gate::policy(Sitting::class, SittingPolicy::class);

        // Platform super admins bypass every permission check (docs/04 §5).
        Gate::before(function ($user) {
            if ($user instanceof User && app(PermissionResolver::class)->isPlatformSuperAdmin($user)) {
                return true;
            }

            return null; // defer to policies
        });
    }
}
