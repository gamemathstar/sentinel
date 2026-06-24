<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Scheduling\Models\Venue;
use App\Modules\Scheduling\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VenueController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly PermissionResolver $permissions,
    ) {}

    public function index(): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_READ);

        return response()->json(['data' => Venue::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_MANAGE);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:200'],
            'capacity' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->scheduling->createVenue($data), 201);
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
