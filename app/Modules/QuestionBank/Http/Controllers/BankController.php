<?php

namespace App\Modules\QuestionBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\StaffGroup;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\QuestionBank\Services\BankVisibilityResolver;
use App\Modules\QuestionBank\Services\QuestionBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Question Banks: list (visibility-filtered), create, share to users/groups, manage groups. */
class BankController extends Controller
{
    public function __construct(
        private readonly QuestionBankService $banks,
        private readonly BankVisibilityResolver $visibility,
    ) {}

    /** Only the banks the caller may read (docs/18). */
    public function index(): JsonResponse
    {
        $ids = $this->visibility->readableBankIds(Auth::user());

        return response()->json(
            QuestionBank::whereIn('id', $ids)
                ->with('ownerOrgNode:id,name,type')
                ->withCount(['items', 'sharedUsers', 'sharedGroups'])
                ->get()
        );
    }

    /** A bank with its current shares (for the manage panel). */
    public function show(string $id): JsonResponse
    {
        $bank = QuestionBank::with([
            'ownerOrgNode:id,name,type',
            'sharedUsers:id,full_name,email',
            'sharedGroups:id,name',
        ])->withCount('items')->findOrFail($id);
        $this->authorize('view', $bank);

        return response()->json($bank);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', QuestionBank::class);
        $data = $request->validate([
            'name' => ['required', 'string'],
            'owner_org_node_id' => ['nullable', 'uuid'],
            'visibility' => ['required', Rule::in(QuestionBank::VISIBILITIES)],
        ]);

        $bank = $this->banks->create($data['name'], $data['owner_org_node_id'] ?? null, $data['visibility']);

        return response()->json($bank, 201);
    }

    public function shareUser(Request $request, string $id): JsonResponse
    {
        $bank = QuestionBank::findOrFail($id);
        $this->authorize('share', $bank);
        $data = $request->validate(['user_id' => ['required', 'uuid'], 'can_edit' => ['sometimes', 'boolean']]);

        $this->banks->shareWithUser($bank, $data['user_id'], $data['can_edit'] ?? false);

        return response()->json(['shared' => true]);
    }

    public function shareGroup(Request $request, string $id): JsonResponse
    {
        $bank = QuestionBank::findOrFail($id);
        $this->authorize('share', $bank);
        $data = $request->validate(['group_id' => ['required', 'uuid'], 'can_edit' => ['sometimes', 'boolean']]);

        $this->banks->shareWithGroup($bank, $data['group_id'], $data['can_edit'] ?? false);

        return response()->json(['shared' => true]);
    }

    public function unshareUser(string $id, string $userId): JsonResponse
    {
        $bank = QuestionBank::findOrFail($id);
        $this->authorize('share', $bank);
        $this->banks->unshareUser($bank, $userId);

        return response()->json(['removed' => true]);
    }

    public function unshareGroup(string $id, string $groupId): JsonResponse
    {
        $bank = QuestionBank::findOrFail($id);
        $this->authorize('share', $bank);
        $this->banks->unshareGroup($bank, $groupId);

        return response()->json(['removed' => true]);
    }

    public function groups(): JsonResponse
    {
        return response()->json(StaffGroup::withCount('members')->get());
    }

    public function createGroup(Request $request): JsonResponse
    {
        $this->authorize('create', QuestionBank::class);
        $data = $request->validate([
            'name' => ['required', 'string'],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['uuid'],
        ]);

        $group = $this->banks->createGroup($data['name']);
        foreach ($data['member_ids'] ?? [] as $uid) {
            $this->banks->addGroupMember($group, $uid);
        }

        return response()->json($group, 201);
    }
}
