<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Imap\StalwartClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BlockedIpController extends Controller
{
    public function index(Request $request, StalwartClient $stalwart): Response
    {
        $currentIp = (string) $request->ip();
        $error = null;
        $entries = [];

        if (! $stalwart->enabled()) {
            $error = 'Mail client hosting is not enabled on this platform.';
        } else {
            try {
                $entries = $stalwart->listBlockedIps();
            } catch (Throwable $e) {
                Log::warning('Could not list Stalwart blocked IPs', ['error' => $e->getMessage()]);
                $error = 'Could not load the blocked IP list from the mail server.';
            }
        }

        return Inertia::render('Admin/BlockedIps/Index', [
            'entries' => array_map(fn (array $entry) => [
                'id' => $entry['id'],
                'ip' => $entry['ip'],
                'reason' => $entry['reason'],
                'reason_label' => $this->reasonLabel($entry['reason']),
                'created' => $entry['created'],
                'is_current' => $stalwart->matchesBlockedAddress($entry['ip'], $currentIp),
            ], $entries),
            'currentIp' => $currentIp,
            'enabled' => $stalwart->enabled(),
            'error' => $error,
        ]);
    }

    public function unblock(Request $request, StalwartClient $stalwart): RedirectResponse
    {
        abort_unless($stalwart->enabled(), 422, 'Mail client hosting is not enabled on this platform.');

        $validated = $request->validate([
            'ip' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || ! $this->validBlockedAddress($value)) {
                    $fail('Enter a valid IP address.');
                }
            }],
        ]);

        try {
            $stalwart->unblockIp($validated['ip']);
        } catch (Throwable $e) {
            Log::warning('Could not unblock Stalwart IP', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not unblock that IP. Try again.');
        }

        return back()->with('success', 'Unblocked '.$validated['ip'].'.');
    }

    protected function validBlockedAddress(string $value): bool
    {
        $value = trim($value);
        $address = str_contains($value, '/') ? explode('/', $value, 2)[0] : $value;

        return filter_var($address, FILTER_VALIDATE_IP) !== false;
    }

    protected function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'authFailure' => 'Failed logins',
            'rcptToFailure' => 'Recipient probing',
            'loitering' => 'Loitering',
            'portScanning' => 'Port scanning',
            'manual' => 'Manual',
            'other' => 'Other',
            default => $reason !== '' ? $reason : 'Blocked',
        };
    }
}
