<?php

namespace App\Modules\Authoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authoring\Models\ScoringRule;
use App\Modules\Authoring\Services\ScoringRuleService;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScoringRuleController extends Controller
{
    public function __construct(
        private readonly ScoringRuleService $rules,
        private readonly PermissionResolver $permissions,
    ) {}

    public function index(): JsonResponse
    {
        $this->ensureCanManage();

        return response()->json(ScoringRule::paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCanManage();

        $data = $request->validate([
            'name' => ['required', 'string'],
            'policy' => ['required', 'array'],
        ]);

        return response()->json($this->rules->create($data['name'], $data['policy']), 201);
    }

    private function ensureCanManage(): void
    {
        abort_unless($this->permissions->can(Auth::user(), Permissions::SCORING_RULE_MANAGE), 403);
    }
}
