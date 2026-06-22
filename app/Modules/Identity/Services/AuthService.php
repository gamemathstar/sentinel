<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Exceptions\InvalidCredentials;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Authentication: password verification, MFA challenge, and bearer-token sessions
 * (docs/01 §4.1, docs/04 §4). Tokens are "{session_id}.{secret}"; only the secret's hash
 * is stored, so a database read cannot mint a working token (docs/04 zero-trust).
 */
class AuthService
{
    private const SESSION_TTL_HOURS = 12;

    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(private readonly TotpService $totp) {}

    /**
     * Verify email + password. If the user has MFA enabled, returns an mfa challenge
     * instead of a token; the caller then calls completeMfa().
     *
     * @return array{status:'authenticated', token:string, session:AuthSession, user:User}
     *                                                                                     |array{status:'mfa_required', challenge:string}
     */
    public function attempt(string $email, string $password, ?string $ip = null, ?string $ua = null): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->status !== 'active' || ! Hash::check($password, $user->password_hash)) {
            throw new InvalidCredentials;
        }

        if ($user->mfa_enabled) {
            return ['status' => 'mfa_required', 'challenge' => $this->issueChallenge($user)];
        }

        return $this->issueSession($user, $ip, $ua);
    }

    /** Complete a login that required MFA by presenting the challenge + a TOTP code. */
    public function completeMfa(string $challenge, string $code, ?string $ip = null, ?string $ua = null): array
    {
        $userId = $this->openChallenge($challenge);
        $user = User::findOrFail($userId);

        if (! $this->verifyTotp($user, $code)) {
            throw new InvalidCredentials('Invalid MFA code.');
        }

        return $this->issueSession($user, $ip, $ua);
    }

    /** Resolve a bearer token to its user, or null if invalid/expired/revoked. */
    public function authenticate(string $token): ?User
    {
        if (! str_contains($token, '.')) {
            return null;
        }
        [$sessionId, $secret] = explode('.', $token, 2);

        $session = AuthSession::find($sessionId);
        if (! $session || ! $session->isActive()) {
            return null;
        }
        if (! hash_equals((string) $session->refresh_token_hash, hash('sha256', $secret))) {
            return null;
        }

        $session->forceFill(['last_active_at' => Carbon::now()])->save();

        return $session->user;
    }

    public function logout(string $token): void
    {
        if (! str_contains($token, '.')) {
            return;
        }
        [$sessionId] = explode('.', $token, 2);
        AuthSession::whereKey($sessionId)->update(['revoked_at' => Carbon::now()]);
    }

    // --- MFA enrolment ------------------------------------------------------

    /** Begin TOTP enrolment: returns the shared secret + provisioning URI for a QR. */
    public function enrollTotp(User $user): array
    {
        $secret = $this->totp->generateSecret();

        $factor = new MfaFactor(['user_id' => $user->id, 'type' => 'totp', 'confirmed' => false]);
        $factor->secret = $secret;
        $factor->save();

        return ['secret' => $secret, 'uri' => $this->totp->provisioningUri($secret, $user->email)];
    }

    /** Confirm enrolment with a code; enables MFA for the user on success. */
    public function confirmTotp(User $user, string $code): bool
    {
        $factor = MfaFactor::where('user_id', $user->id)->where('type', 'totp')->latest()->first();
        if (! $factor || ! $this->totp->verify($factor->secret, $code)) {
            return false;
        }

        $factor->forceFill(['confirmed' => true])->save();
        $user->forceFill(['mfa_enabled' => true])->save();

        return true;
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $factor = MfaFactor::where('user_id', $user->id)
            ->where('type', 'totp')->where('confirmed', true)->latest()->first();

        return $factor !== null && $this->totp->verify($factor->secret, $code);
    }

    // --- internals ----------------------------------------------------------

    private function issueSession(User $user, ?string $ip, ?string $ua): array
    {
        $secret = Str::random(48);
        $session = AuthSession::create([
            'user_id' => $user->id,
            'ip' => $ip,
            'user_agent' => $ua,
            'refresh_token_hash' => hash('sha256', $secret),
            'last_active_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours(self::SESSION_TTL_HOURS),
        ]);

        return [
            'status' => 'authenticated',
            'token' => $session->id.'.'.$secret,
            'session' => $session,
            'user' => $user,
        ];
    }

    private function issueChallenge(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $user->id,
            'exp' => time() + self::CHALLENGE_TTL_SECONDS,
        ]));
    }

    private function openChallenge(string $challenge): string
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true);
        } catch (\Throwable) {
            throw new InvalidCredentials('Invalid MFA challenge.');
        }
        if (! isset($data['uid'], $data['exp']) || $data['exp'] < time()) {
            throw new InvalidCredentials('Expired MFA challenge.');
        }

        return $data['uid'];
    }
}
