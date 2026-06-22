<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Services\AuthService;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication (IAM module). Resolves the token to a user, binds it as the
 * authenticated user (so policies/Gate work), and derives the TenantContext from the
 * user's institution — replacing the earlier header stub (SetTenantContext). A platform
 * super admin (no institution) gets platform scope. Also sets the Postgres RLS GUC for
 * defense in depth (docs/03 §9, docs/04 §4).
 */
class Authenticate
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $user = $token ? $this->auth->authenticate($token) : null;

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        Auth::setUser($user);

        if ($user->institution_id === null) {
            // Platform-level subject: unscoped access across tenants.
            $this->tenant->actAsPlatform($user->id);
            $this->setRlsGuc('all-tenants');
        } else {
            $this->tenant->set($user->institution_id, $user->id);
            $this->setRlsGuc($user->institution_id);
        }

        return $next($request);
    }

    private function setRlsGuc(string $value): void
    {
        DB::statement('SELECT set_config(?, ?, false)', ['app.current_institution', $value]);
    }
}
