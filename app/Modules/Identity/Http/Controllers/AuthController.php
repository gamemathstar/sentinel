<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Authentication endpoints (IAM module): login, MFA, logout, me, MFA enrolment. */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PermissionResolver $permissions,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->auth->attempt($data['email'], $data['password'], $request->ip(), $request->userAgent());

        if ($result['status'] === 'mfa_required') {
            return response()->json(['status' => 'mfa_required', 'challenge' => $result['challenge']], 200);
        }

        return $this->tokenResponse($result);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $result = $this->auth->completeMfa($data['challenge'], $data['code'], $request->ip(), $request->userAgent());

        return $this->tokenResponse($result);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($token = $request->bearerToken()) {
            $this->auth->logout($token);
        }

        return response()->json(['status' => 'logged_out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'user' => $user->only(['id', 'email', 'full_name', 'institution_id', 'mfa_enabled']),
            'permissions' => $this->permissions->permissionKeys($user),
            'is_platform_super_admin' => $this->permissions->isPlatformSuperAdmin($user),
        ]);
    }

    public function enrollMfa(): JsonResponse
    {
        return response()->json($this->auth->enrollTotp(Auth::user()));
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $ok = $this->auth->confirmTotp(Auth::user(), $data['code']);

        return response()->json(['confirmed' => $ok], $ok ? 200 : 422);
    }

    private function tokenResponse(array $result): JsonResponse
    {
        return response()->json([
            'status' => 'authenticated',
            'token' => $result['token'],
            'expires_at' => $result['session']->expires_at,
            'user' => $result['user']->only(['id', 'email', 'full_name', 'institution_id']),
        ], 201);
    }
}
