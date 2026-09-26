<?php

namespace App\Services\Impersonation;

use App\Models\ImpersonationAction;
use App\Models\ImpersonationLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantResolver;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Read-only "log in as" for platform admins.
 *
 * The admin's own session is replaced by one authenticated as the target
 * (without a remember cookie). The admin id, audit log id and start time are
 * kept in the session so ImpersonationGuard can enforce read-only access and
 * the TTL, and so leaving/logging out restores the admin.
 */
class ImpersonationService
{
    public const SESSION_IMPERSONATOR = 'impersonator_id';

    public const SESSION_LOG = 'impersonation_log_id';

    public const SESSION_STARTED = 'impersonation_started_at';

    public function __construct(public TenantResolver $tenants) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) config('impersonation.ttl_minutes', 30));
    }

    public function isImpersonating(?Request $request = null): bool
    {
        $request ??= request();

        return $request->hasSession() && $request->session()->has(self::SESSION_IMPERSONATOR);
    }

    public function impersonatorId(Request $request): ?int
    {
        $id = $request->session()->get(self::SESSION_IMPERSONATOR);

        return $id !== null ? (int) $id : null;
    }

    public function currentLog(Request $request): ?ImpersonationLog
    {
        $id = $request->session()->get(self::SESSION_LOG);

        return $id ? ImpersonationLog::query()->find($id) : null;
    }

    public function startedAt(Request $request): ?CarbonInterface
    {
        $ts = $request->session()->get(self::SESSION_STARTED);

        return $ts ? now()->setTimestamp((int) $ts) : null;
    }

    public function expiresAt(Request $request): ?CarbonInterface
    {
        return $this->startedAt($request)?->copy()->addMinutes($this->ttlMinutes());
    }

    public function hasExpired(Request $request): bool
    {
        $expires = $this->expiresAt($request);

        return $expires === null || now()->greaterThanOrEqualTo($expires);
    }

    /**
     * Begin impersonating $target inside $organization. Returns the tenant
     * workspace URL to navigate to.
     *
     * @throws ImpersonationDenied
     */
    public function start(Request $request, User $admin, User $target, Organization $organization, string $reason): string
    {
        if (! $admin->isPlatformAdmin()) {
            throw new ImpersonationDenied('Only platform admins can log in as another user.');
        }

        if ($this->isImpersonating($request)) {
            throw new ImpersonationDenied('You are already impersonating a user. Return to admin first.');
        }

        if ($target->is($admin)) {
            throw new ImpersonationDenied('You cannot log in as yourself.');
        }

        if ($target->isPlatformAdmin()) {
            throw new ImpersonationDenied('Platform admins cannot be impersonated.');
        }

        if (! $target->organizations()->exists()) {
            throw new ImpersonationDenied('This user does not belong to any account.');
        }

        if (! $target->organizations()->whereKey($organization->id)->exists()) {
            throw new ImpersonationDenied('This user is not a member of this account.');
        }

        $this->closeStaleLogs($admin->id);

        $log = ImpersonationLog::query()->create([
            'admin_id' => $admin->id,
            'user_id' => $target->id,
            'organization_id' => $organization->id,
            'reason' => $reason,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'started_at' => now(),
        ]);

        $session = $request->session();

        // Fresh session for the impersonated identity: drops the admin's
        // password confirmation, intended URLs, flashes, etc.
        $session->invalidate();
        Auth::guard('web')->login($target, false);
        $session->regenerateToken();

        $session->put([
            self::SESSION_IMPERSONATOR => $admin->id,
            self::SESSION_LOG => $log->id,
            self::SESSION_STARTED => now()->getTimestamp(),
            'current_organization_id' => $organization->id,
        ]);
        $session->save();

        return $this->tenants->workspaceUrl($organization, '/emails');
    }

    /**
     * End the current impersonation, restore the admin (when still a valid
     * platform admin) and return the URL to send the browser to.
     *
     * Never calls Auth::logout(): that would cycle the impersonated user's
     * remember_token and sign them out of their other remembered devices.
     */
    public function stop(Request $request, string $endReason): string
    {
        $log = $this->currentLog($request);
        $adminId = $this->impersonatorId($request);

        if ($log && $log->isOpen()) {
            $log->forceFill(['ended_at' => now(), 'end_reason' => $endReason])->save();
        }

        $admin = $adminId ? User::query()->find($adminId) : null;
        $session = $request->session();
        $guard = Auth::guard('web');

        if ($admin && $admin->isPlatformAdmin()) {
            // Flushing the session drops the impersonated identity from this
            // browser only; the admin's own remember cookie is left intact.
            $session->invalidate();
            $guard->login($admin, false);
            $session->regenerateToken();
            $session->save();

            return $this->adminReturnUrl($log?->organization_id);
        }

        // Impersonator deleted or demoted: sign this browser out entirely
        // (current device only, the target's remember_token is untouched).
        $guard->logoutCurrentDevice();
        $session->invalidate();
        $session->regenerateToken();
        $session->save();

        return $this->centralUrl(route('login', absolute: false));
    }

    /**
     * Close any open log older than the TTL (browser closed, session lost…).
     */
    public function closeStaleLogs(?int $adminId = null): int
    {
        return ImpersonationLog::query()
            ->open()
            ->when($adminId, fn ($q) => $q->where('admin_id', $adminId))
            ->where('started_at', '<=', now()->subMinutes($this->ttlMinutes()))
            ->update(['ended_at' => now(), 'end_reason' => 'expired', 'updated_at' => now()]);
    }

    public function recordAction(int $logId, Request $request, ?Response $response, bool $blocked): void
    {
        try {
            ImpersonationAction::query()->create([
                'impersonation_log_id' => $logId,
                'method' => Str::upper(Str::limit($request->method(), 10, '')),
                'route_name' => $request->route()?->getName(),
                'path' => Str::limit('/'.ltrim($request->path(), '/'), 2000, ''),
                'status' => $response?->getStatusCode(),
                'blocked' => $blocked,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Audit a refused "log in as" attempt (policy denial, suspended account…)
     * as an already-closed log with end_reason "denied".
     */
    public function recordDenied(Request $request, User $admin, ?User $target, ?Organization $organization, string $message): void
    {
        try {
            $now = now();

            ImpersonationLog::query()->create([
                'admin_id' => $admin->id,
                'user_id' => $target?->id,
                'organization_id' => $organization?->id,
                'reason' => Str::limit((string) $request->input('reason', ''), 500, ''),
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'started_at' => $now,
                'ended_at' => $now,
                'end_reason' => 'denied',
                'denied_reason' => Str::limit($message, 500, ''),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function adminReturnUrl(?int $organizationId): string
    {
        $path = $organizationId && Organization::query()->whereKey($organizationId)->exists()
            ? route('admin.accounts.show', $organizationId, absolute: false)
            : route('admin.accounts', absolute: false);

        return $this->centralUrl($path);
    }

    public function centralUrl(string $path): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $this->tenants->centralHosts()[0] ?? $this->tenants->baseDomain();

        return $scheme.'://'.$host.'/'.ltrim($path, '/');
    }

    /**
     * Members of an account the admin may pick in the "Log in as" modal,
     * owners first.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesFor(Organization $organization, ?User $admin): array
    {
        $rank = ['owner' => 0, 'admin' => 1, 'member' => 2];

        return $organization->users()
            ->orderBy('users.name')
            ->get()
            ->sortBy(fn (User $user) => $rank[$user->pivot->role] ?? 3)
            ->map(function (User $user) use ($admin) {
                $disabledReason = match (true) {
                    $admin !== null && $user->is($admin) => 'This is you',
                    $user->isPlatformAdmin() => 'Platform admin',
                    default => null,
                };

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    'is_platform_admin' => $user->isPlatformAdmin(),
                    'can_impersonate' => $disabledReason === null,
                    'disabled_reason' => $disabledReason,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentLogsFor(Organization $organization, int $limit = 10): array
    {
        return ImpersonationLog::query()
            ->where('organization_id', $organization->id)
            ->with(['admin:id,name', 'user:id,name,email'])
            ->withCount(['actions', 'actions as blocked_actions_count' => fn ($q) => $q->where('blocked', true)])
            ->latest('started_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map->toAdminArray()
            ->values()
            ->all();
    }

    /**
     * Shared Inertia payload for the impersonation banner.
     *
     * @return array<string, mixed>|null
     */
    public function sharedState(Request $request): ?array
    {
        if (! $this->isImpersonating($request) || ! $request->user()) {
            return null;
        }

        $impersonator = DB::table('users')->where('id', $this->impersonatorId($request))->value('name');

        return [
            'active' => true,
            'user' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'impersonator' => ['name' => $impersonator],
            'expires_at' => $this->expiresAt($request)?->toIso8601String(),
        ];
    }
}
