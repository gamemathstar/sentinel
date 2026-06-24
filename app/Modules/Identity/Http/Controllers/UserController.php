<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lists staff in the current tenant — used by the share-with-user picker. */
class UserController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('institution_id', $this->tenant->institutionId())
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('full_name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")
            ))
            ->orderBy('full_name')
            ->limit(50)
            ->get(['id', 'full_name', 'email']);

        return response()->json($users);
    }
}
