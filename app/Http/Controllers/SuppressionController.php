<?php

namespace App\Http\Controllers;

use App\Models\Suppression;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SuppressionController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $suppressions = $organization->suppressions()
            ->latest()
            ->get()
            ->map(fn (Suppression $suppression) => $suppression->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Suppressions/Index', [
            'suppressions' => $suppressions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        Suppression::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => Str::lower($validated['email']),
            ],
            [
                'reason' => $validated['reason'] ?? 'Manual suppression',
                'source' => 'manual',
            ],
        );

        return back()->with('success', 'Address suppressed.');
    }

    public function destroy(Request $request, Suppression $suppression): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($suppression->organization_id === $organization->id, 404);

        $suppression->delete();

        return back()->with('success', 'Removed from suppressions.');
    }
}
