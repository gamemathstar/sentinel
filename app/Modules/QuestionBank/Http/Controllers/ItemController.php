<?php

namespace App\Modules\QuestionBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\QuestionBank\Http\Requests\ImportRequest;
use App\Modules\QuestionBank\Http\Requests\StoreItemRequest;
use App\Modules\QuestionBank\Import\ImportManager;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\QuestionBank\Services\AnswerKeyVault;
use App\Modules\QuestionBank\Services\BankVisibilityResolver;
use App\Modules\QuestionBank\Services\ItemService;
use App\Modules\QuestionBank\Services\ReviewService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * REST surface for the Question Bank. Tenant scoping is automatic (BelongsToTenant +
 * SetTenantContext middleware), so handlers never filter by institution by hand.
 * Responses never include answer keys — they live in the vault (docs/04 §2).
 */
class ItemController extends Controller
{
    public function __construct(
        private readonly ItemService $items,
        private readonly ReviewService $reviews,
        private readonly ImportManager $importer,
        private readonly TenantContext $tenant,
        private readonly BankVisibilityResolver $bankVisibility,
    ) {}

    /**
     * Browse questions across the banks the caller may read (docs/18), filterable by bank,
     * course, specialization, type, status, tag, and a stem search.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Item::class);
        $user = Auth::user();

        $query = Item::query()
            ->with(['currentVersion:id,item_id,content,state', 'questionBank:id,name', 'course:id,name', 'specialization:id,name'])
            ->when($request->query('question_bank_id'), fn ($q, $v) => $q->where('question_bank_id', $v))
            ->when($request->query('course_org_node_id'), fn ($q, $v) => $q->where('course_org_node_id', $v))
            ->when($request->query('specialization_org_node_id'), fn ($q, $v) => $q->where('specialization_org_node_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('tag'), fn ($q, $v) => $q->whereJsonContains('tags', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->whereHas('currentVersion',
                fn ($w) => $w->whereRaw("content->>'stem' ILIKE ?", ['%'.$v.'%'])))
            ->latest();

        // Restrict to readable banks unless the caller manages all banks.
        if (! $this->bankVisibility->canManageAll($user)) {
            $query->whereIn('question_bank_id', $this->bankVisibility->readableBankIds($user));
        }

        return response()->json($query->paginate((int) $request->query('per_page', 25)));
    }

    public function show(string $id): JsonResponse
    {
        $item = Item::with(['currentVersion', 'questionBank', 'course:id,name', 'specialization:id,name'])->findOrFail($id);
        $this->authorize('view', $item);

        $payload = $item->toArray();

        // For an authorized editor of the bank, surface the correct answer so it can be
        // edited — fetched JIT from the vault, never stored beside the question (docs/04 §2).
        $bank = $item->questionBank;
        if ($bank && app(BankVisibilityResolver::class)->canEdit(Auth::user(), $bank)) {
            $payload['answer'] = $item->current_version_id
                ? app(AnswerKeyVault::class)->fetch($item->current_version_id)
                : null;
        }

        return response()->json($payload);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $this->authorize('create', Item::class);

        // The question must belong to a bank the author may write to (docs/18).
        $bank = QuestionBank::findOrFail($request->input('question_bank_id'));
        $this->authorize('update', $bank);

        $item = $this->items->createItem($request->validated());

        return response()->json($item, 201);
    }

    public function storeVersion(StoreItemRequest $request, string $id): JsonResponse
    {
        $item = Item::findOrFail($id);
        $this->authorize('update', $item);

        // Editing creates a new immutable version (content + answer) and refreshes the
        // item-level classification (course / specialization / tags). The bank is fixed.
        $version = $this->items->addVersion($item, $request->validated());
        $item->update([
            'course_org_node_id' => $request->input('course_org_node_id'),
            'specialization_org_node_id' => $request->input('specialization_org_node_id'),
            'tags' => $request->input('tags', []),
        ]);

        return response()->json($version, 201);
    }

    public function review(Request $request, string $versionId): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject,revise'],
            'comment' => ['nullable', 'string'],
        ]);

        $version = ItemVersion::whereHas('item')->findOrFail($versionId);
        $this->authorize('review', $version);
        $reviewerId = $this->tenant->userId() ?? abort(400, 'No acting reviewer in context.');

        $review = $this->reviews->submitReview($version, $reviewerId, $validated['decision'], $validated['comment'] ?? null);

        return response()->json(['review' => $review, 'version_state' => $version->fresh()->state], 201);
    }

    public function import(ImportRequest $request): JsonResponse
    {
        $this->authorize('import', Item::class);

        $summary = $this->importer->import(
            $request->string('body'),
            $request->string('format'),
            $request->input('defaults', []),
        );

        return response()->json($summary, 201);
    }
}
