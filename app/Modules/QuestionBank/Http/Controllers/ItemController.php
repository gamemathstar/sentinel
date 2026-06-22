<?php

namespace App\Modules\QuestionBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\QuestionBank\Http\Requests\ImportRequest;
use App\Modules\QuestionBank\Http\Requests\StoreItemRequest;
use App\Modules\QuestionBank\Import\ImportManager;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\QuestionBank\Services\ItemService;
use App\Modules\QuestionBank\Services\ReviewService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Item::class);

        $items = Item::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->with('currentVersion')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json($items);
    }

    public function show(string $id): JsonResponse
    {
        $item = Item::with(['currentVersion', 'versions', 'orgNodes'])->findOrFail($id);
        $this->authorize('view', $item);

        return response()->json($item);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $this->authorize('create', Item::class);

        $item = $this->items->createItem($request->validated());

        return response()->json($item, 201);
    }

    public function storeVersion(StoreItemRequest $request, string $id): JsonResponse
    {
        $item = Item::findOrFail($id);
        $this->authorize('update', $item);

        $version = $this->items->addVersion($item, $request->validated());

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
