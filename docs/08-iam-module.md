# 08 — Identity & Access (IAM) Module

Real authentication, scoped role-based access control, and MFA — replacing the
header-based tenant stub from the Question Bank phase. The Question Bank API is now
genuinely protected: requests need a bearer token, and each action is checked against the
caller's resolved permissions.

Covered by **13 IAM tests** (and the Question Bank API tests now run under real auth);
the whole suite is **36 passing**.

## Code layout

```
app/Modules/Identity/
  Models/        User, Role, Permission, RoleAssignment, AuthSession, MfaFactor
  Services/
    AuthService.php          password login, token sessions, MFA challenge
    PermissionResolver.php    scoped permission resolution (+ super-admin check)
    RbacProvisioner.php       idempotent seed of catalog + system roles
    TotpService.php           RFC 6238 TOTP (no external dependency)
  Support/Permissions.php     permission catalog + system-role definitions
  Http/Controllers/AuthController.php
  Exceptions/InvalidCredentials.php

app/Modules/Tenancy/Services/OrgNodeService.php   materialized-path org tree
app/Http/Middleware/Authenticate.php              bearer auth + tenant context
```

## Authentication

- **Login** (`POST /api/auth/login`) verifies email + password. If the user has MFA
  enabled it returns an `mfa_required` challenge; otherwise it issues a token.
- **Tokens** are `"{session_id}.{secret}"`. Only `sha256(secret)` is stored in the
  `sessions` row, so **a database read cannot reconstruct a working token** (docs/04
  zero-trust). Sessions carry an expiry and can be revoked (logout).
- **MFA** is TOTP (RFC 6238), compatible with Google Authenticator/Authy. Enrolment
  returns a secret + `otpauth://` URI; the secret is encrypted at rest in `mfa_factors`.

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `POST /api/auth/login` | public | email+password → token or MFA challenge |
| `POST /api/auth/mfa/verify` | public | challenge + TOTP code → token |
| `GET /api/auth/me` | bearer | current user + resolved permissions |
| `POST /api/auth/logout` | bearer | revoke the token |
| `POST /api/auth/mfa/enroll` | bearer | begin TOTP enrolment |
| `POST /api/auth/mfa/confirm` | bearer | confirm enrolment, enable MFA |

## Authorization model (scoped RBAC)

Permissions are resolved as the **union of role permissions over a subject's scoped role
assignments** (docs/04 §5):

```
effective(user, resourceNode) = ⋃ { role.permissions
    | assignment ∈ user.assignments
    ∧ (assignment.scope is null            // institution-wide
       OR assignment.scope ⊒ resourceNode) // ancestor-or-self in the org tree
}
```

- A **null scope** grants institution-wide; a **node-scoped** assignment grants only on
  that org node and its subtree, tested via the org `path` materialized path — verified
  by `RbacTest`: an author scoped to *Physics* can create there and in its topics, but
  not in sibling *Chemistry*, and not institution-wide.
- **System roles** (`platform_super_admin`, `institution_admin`, `exam_officer`,
  `question_author`, `question_reviewer`, `question_approver`, `student`) are seeded
  idempotently by `RbacProvisioner`. Custom per-tenant roles are just rows with a non-null
  `institution_id`.
- The **platform super admin** bypasses all checks via a `Gate::before` hook.
- Enforcement is in `ItemPolicy` (called by `$this->authorize(...)` in the controller).
  The Question Bank routes now reject unauthenticated requests (401) and unauthorized
  ones (403) — both covered by tests.

## How tenant context is set now

`Authenticate` middleware resolves the bearer token → user, binds it as the
authenticated user (so Gate/policies work), and sets `TenantContext` from the user's
institution (platform scope if they have none). It also sets the Postgres
`app.current_institution` GUC that the RLS policies key off (docs/03 §9). The old
`X-Institution-Id` header stub is retired for these routes.

## Provisioning roles in a deployment

```php
app(\App\Modules\Identity\Services\RbacProvisioner::class)->provision(); // catalog + system roles
// then assign, e.g.:
RoleAssignment::create([
    'user_id' => $user->id,
    'role_id' => Role::whereNull('institution_id')->where('name','question_author')->value('id'),
    'scope_org_node_id' => $departmentId,   // or null for institution-wide
    'institution_id' => $user->institution_id,
]);
```

## Notes / future work

- A `db.provision-rbac` artisan command and an admin UI for role management are not yet
  built; provisioning is via the service above (and the test helpers).
- Token sessions are opaque bearer tokens (not JWTs) by design — revocation is immediate
  and server-authoritative. A refresh-token rotation flow can layer on later.
