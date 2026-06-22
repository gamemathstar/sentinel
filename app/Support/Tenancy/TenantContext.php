<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Holds the institution (tenant) and acting user for the current request/job.
 *
 * Registered as a singleton. HTTP middleware (SetTenantContext) populates it from the
 * authenticated subject; tests and queue jobs set it explicitly. The BelongsToTenant
 * trait reads it to scope every query and to stamp institution_id on writes, so tenant
 * isolation does not depend on each query remembering a where clause (docs/03 §9).
 */
class TenantContext
{
    private ?string $institutionId = null;

    private ?string $userId = null;

    /** When true, queries are not tenant-scoped (platform-admin / system tasks). */
    private bool $platformScope = false;

    public function set(?string $institutionId, ?string $userId = null): void
    {
        $this->institutionId = $institutionId;
        $this->userId = $userId;
        $this->platformScope = false;
    }

    public function actAsPlatform(?string $userId = null): void
    {
        $this->platformScope = true;
        $this->institutionId = null;
        $this->userId = $userId;
    }

    public function clear(): void
    {
        $this->institutionId = null;
        $this->userId = null;
        $this->platformScope = false;
    }

    public function hasInstitution(): bool
    {
        return $this->institutionId !== null;
    }

    public function isPlatformScope(): bool
    {
        return $this->platformScope;
    }

    public function institutionId(): ?string
    {
        return $this->institutionId;
    }

    public function requireInstitutionId(): string
    {
        if ($this->institutionId === null) {
            throw new RuntimeException('No institution in the current tenant context.');
        }

        return $this->institutionId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }
}
