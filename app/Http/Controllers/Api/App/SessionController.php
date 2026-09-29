<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $token = $user->createToken($validated['device_name'] ?? 'MailDesk', ['mobile'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
            'organizations' => $this->organizations($user),
            'realtime' => $this->realtime(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'organizations' => $this->organizations($user),
            'realtime' => $this->realtime(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * @return list<array{id: int, name: string, subdomain: string|null, role: string|null, status: string|null}>
     */
    private function organizations(User $user): array
    {
        return $user->organizations()
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'subdomain' => $organization->subdomain,
                'role' => $organization->pivot?->role,
                'status' => $organization->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{key: string|null, host: string|null, port: int, scheme: string, auth_endpoint: string}
     */
    private function realtime(): array
    {
        return [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => config('broadcasting.connections.reverb.options.host'),
            'port' => (int) config('broadcasting.connections.reverb.options.port', 8080),
            'scheme' => (string) config('broadcasting.connections.reverb.options.scheme', 'https'),
            'auth_endpoint' => url('/api/app/broadcasting/auth'),
        ];
    }
}
