<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Populates the per-request TenantContext.
 *
 * STUB: until the IAM module ships authentication, the acting subject and tenant are
 * taken from `X-Institution-Id` / `X-User-Id` headers (or `X-Platform-Scope: 1` for
 * platform-wide work). When IAM lands, this resolves them from the authenticated user
 * and their role assignment instead — the rest of the app already reads TenantContext,
 * so nothing downstream changes. It also sets the Postgres RLS GUC for defense in depth
 * (docs/03 §9, docs/04 §4).
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->boolean('platform') || $request->header('X-Platform-Scope')) {
            $this->context->actAsPlatform($request->header('X-User-Id'));
            $this->applyRlsGuc('all-tenants');

            return $next($request);
        }

        $institutionId = $request->header('X-Institution-Id');
        if (! $institutionId) {
            abort(400, 'Missing tenant context (X-Institution-Id).');
        }

        $userId = $request->header('X-User-Id');
        if ($userId && ! User::where('id', $userId)->where('institution_id', $institutionId)->exists()) {
            abort(403, 'Acting user does not belong to the given institution.');
        }

        $this->context->set($institutionId, $userId);
        $this->applyRlsGuc($institutionId);

        return $next($request);
    }

    /** Set the session GUC the RLS policies key off of (no-op for superuser DB roles). */
    private function applyRlsGuc(string $value): void
    {
        // set_config(..., true) scopes it to the current transaction/connection lifetime.
        \DB::statement('SELECT set_config(?, ?, false)', ['app.current_institution', $value]);
    }
}
