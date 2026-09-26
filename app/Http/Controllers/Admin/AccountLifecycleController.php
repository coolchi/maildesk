<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountLifecycleController extends Controller
{
    /**
     * Soft-delete (close) an account. The admin must type the account's
     * subdomain or name and re-enter their own password.
     */
    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'current_password:web'],
        ]);

        $typed = Str::lower(trim((string) $request->input('confirmation')));
        $accepted = array_filter([
            Str::lower((string) $organization->subdomain),
            Str::lower((string) $organization->name),
        ]);

        if (! in_array($typed, $accepted, true)) {
            throw ValidationException::withMessages([
                'confirmation' => 'Type the account subdomain or name exactly to confirm.',
            ]);
        }

        DB::transaction(function () use ($organization): void {
            // Stop billing: a closed account no longer counts towards revenue.
            $organization->subscriptions()
                ->whereIn('status', ['active', 'trial', 'past_due'])
                ->update(['status' => 'canceled', 'updated_at' => now()]);

            $organization->forceFill(['mrr' => 0])->save();
            $organization->delete();
        });

        return redirect()
            ->route('admin.accounts')
            ->with('success', "Account {$organization->name} deleted.");
    }
}
