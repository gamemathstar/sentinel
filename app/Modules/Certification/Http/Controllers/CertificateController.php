<?php

namespace App\Modules\Certification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Certification\Models\Certificate;
use App\Modules\Certification\Services\CertificationService;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificationService $certificates,
        private readonly PermissionResolver $permissions,
    ) {}

    /** PUBLIC verification portal — no authentication; the token is the credential. */
    public function verify(string $token): JsonResponse
    {
        $result = $this->certificates->verify($token);

        return response()->json($result, $result['valid'] ? 200 : 404);
    }

    public function issue(Request $request, string $sittingId): JsonResponse
    {
        $this->ensure(Permissions::CERT_ISSUE);

        $sitting = Sitting::findOrFail($sittingId);
        $certificate = $this->certificates->issueForSitting($sitting, $request->boolean('anchor'));

        return response()->json($certificate->only(['id', 'serial', 'verification_token', 'anchor_txid', 'issued_at']), 201);
    }

    public function index(): JsonResponse
    {
        $this->ensure(Permissions::CERT_READ);

        return response()->json(Certificate::latest('issued_at')->paginate(25));
    }

    public function revoke(string $id): JsonResponse
    {
        $this->ensure(Permissions::CERT_REVOKE);

        $certificate = Certificate::findOrFail($id);
        $this->certificates->revoke($certificate);

        return response()->json(['revoked' => true, 'serial' => $certificate->serial]);
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
