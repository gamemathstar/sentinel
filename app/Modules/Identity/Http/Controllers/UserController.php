<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists users in the current tenant — used by the share-with-user and invigilator pickers.
 *
 * A user is a "candidate" if they hold the system `student` role; everyone else is staff.
 * Pass `?role=staff` (e.g. when assigning invigilators, so candidates never appear) or
 * `?role=candidate`. With no filter, all tenant users are returned.
 */
class UserController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $studentRoleId = Role::whereNull('institution_id')->where('name', 'student')->value('id');

        $users = User::query()
            ->where('institution_id', $this->tenant->institutionId())
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('full_name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")
            ))
            ->when($request->query('role') === 'staff', fn (Builder $q) => $q->whereDoesntHave(
                'roleAssignments', fn (Builder $r) => $r->where('role_id', $studentRoleId)
            ))
            ->when($request->query('role') === 'candidate', fn (Builder $q) => $q->whereHas(
                'roleAssignments', fn (Builder $r) => $r->where('role_id', $studentRoleId)
            ))
            ->orderBy('full_name')
            ->limit(50)
            ->get(['id', 'full_name', 'email']);

        return response()->json($users);
    }
}
