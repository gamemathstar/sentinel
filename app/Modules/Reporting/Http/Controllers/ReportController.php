<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Reporting\Models\Report;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\ReportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportingService $reports,
        private readonly PermissionResolver $permissions,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $this->ensure(Permissions::REPORT_GENERATE);
        $data = $request->validate([
            'type' => ['required', Rule::in(ReportCatalog::TYPES)],
            'format' => ['required', Rule::in(ReportCatalog::FORMATS)],
            'params' => ['sometimes', 'array'],
        ]);

        $report = $this->reports->generate($data['type'], $data['format'], $data['params'] ?? []);

        return response()->json($report->only(['id', 'type', 'format', 'status', 'title', 'rows']), 201);
    }

    public function index(): JsonResponse
    {
        $this->ensure(Permissions::REPORT_READ);

        return response()->json(Report::latest()->paginate(25));
    }

    public function show(string $id): JsonResponse
    {
        $this->ensure(Permissions::REPORT_READ);

        return response()->json(Report::findOrFail($id));
    }

    public function download(string $id): StreamedResponse
    {
        $this->ensure(Permissions::REPORT_READ);
        $report = Report::findOrFail($id);
        abort_unless($report->status === 'completed' && $report->path, 404, 'Report artifact not available.');

        return Storage::disk($report->disk)->download(
            $report->path,
            $this->reports->downloadName($report),
            ['Content-Type' => ReportCatalog::mime($report->format)],
        );
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
