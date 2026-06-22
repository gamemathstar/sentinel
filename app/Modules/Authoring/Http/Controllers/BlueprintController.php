<?php

namespace App\Modules\Authoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authoring\Models\Blueprint;
use App\Modules\Authoring\Services\BlueprintService;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BlueprintController extends Controller
{
    public function __construct(
        private readonly BlueprintService $blueprints,
        private readonly PermissionResolver $permissions,
    ) {}

    public function index(): JsonResponse
    {
        $this->ensureCanManage();

        return response()->json(Blueprint::paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCanManage();

        $data = $request->validate([
            'name' => ['required', 'string'],
            'constraints' => ['required', 'array'],
        ]);

        return response()->json($this->blueprints->create($data['name'], $data['constraints']), 201);
    }

    private function ensureCanManage(): void
    {
        abort_unless($this->permissions->can(Auth::user(), Permissions::BLUEPRINT_MANAGE), 403);
    }
}
