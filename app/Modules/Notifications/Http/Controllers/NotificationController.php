<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PermissionResolver $permissions,
    ) {}

    /** A recipient sees their own notifications; a sender can see all (tenant-scoped). */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $query = Notification::latest();
        if (! $this->permissions->can($user, Permissions::NOTIFICATION_SEND)) {
            $query->where('recipient_id', $user->id);
        }

        return response()->json($query->paginate(25));
    }

    public function send(Request $request): JsonResponse
    {
        abort_unless($this->permissions->can(Auth::user(), Permissions::NOTIFICATION_SEND), 403);

        $data = $request->validate([
            'recipient_id' => ['required', 'uuid'],
            'channel' => ['required', Rule::in(Notification::CHANNELS)],
            'event_key' => ['required', 'string'],
            'context' => ['sometimes', 'array'],
            'dedupe_key' => ['sometimes', 'string'],
        ]);

        $notification = $this->notifications->send(
            $data['recipient_id'], $data['channel'], $data['event_key'],
            $data['context'] ?? [], $data['dedupe_key'] ?? null,
        );

        return response()->json($notification->only(['id', 'channel', 'event_key', 'status']), 201);
    }
}
