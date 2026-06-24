<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\OrgNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The tenant's org hierarchy — used to pick course / specialization / bank owner in the UI. */
class OrgNodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $nodes = OrgNode::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderBy('depth')
            ->get(['id', 'name', 'type', 'parent_id', 'depth']);

        return response()->json($nodes);
    }
}
