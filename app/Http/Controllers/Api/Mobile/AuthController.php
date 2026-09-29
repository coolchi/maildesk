<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        public AccountAccess $accountAccess,
        public WorkspaceAccess $workspaceAccess,
    ) {}

    /**
     * Issue a Sanctum token for mobile login.
     */
    public function login(Request $request): JsonResponse
    {
        $this->ensureIsNotRateLimited($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if ($this->accountAccess->isLockedOut($user)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => [$this->accountAccess->lockoutMessage($user)],
            ]);
        }

        if (! $this->workspaceAccess->mailboxLoginAllowed($user)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => [$this->workspaceAccess->mailboxLoginDeniedMessage($user)],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $deviceName = $validated['device_name'] ?? 'Mobile App';
        $token = $user->createToken($deviceName)->plainTextToken;

        $workspaces = $user->organizations()
            ->get()
            ->map(fn ($org) => $org->toWorkspaceArray())
            ->values()
            ->all();

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_platform_admin' => $user->isPlatformAdmin(),
            ],
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * Revoke the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Get the authenticated user and their workspaces.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $workspaces = $user->organizations()
            ->get()
            ->map(fn ($org) => $org->toWorkspaceArray())
            ->values()
            ->all();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_platform_admin' => $user->isPlatformAdmin(),
            ],
            'workspaces' => $workspaces,
        ]);
    }

    protected function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ])],
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(
            Str::lower($request->string('email')).'|'.$request->ip().'|mobile',
        );
    }
}
