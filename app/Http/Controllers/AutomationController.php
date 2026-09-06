<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $automations = $organization->automations()
            ->with('steps')
            ->latest()
            ->get()
            ->map(fn (Automation $automation) => $automation->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Automations/Index', [
            'automations' => $automations,
            'events' => [],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Automations/Show', [
            'id' => null,
            'automation' => null,
        ]);
    }

    public function show(Request $request, Automation $automation): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($automation->organization_id === $organization->id, 404);

        $automation->load('steps');

        return Inertia::render('Automations/Show', [
            'id' => $automation->id,
            'automation' => $automation->toWorkspaceArray(),
        ]);
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($automation->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'status' => ['sometimes', Rule::in(['enabled', 'disabled', 'active', 'paused'])],
            'trigger' => ['sometimes', 'string', 'max:120'],
        ]);

        if (isset($validated['status'])) {
            $validated['status'] = match ($validated['status']) {
                'enabled', 'active' => 'active',
                default => 'paused',
            };
        }

        $automation->fill([
            'name' => $validated['name'] ?? $automation->name,
            'status' => $validated['status'] ?? $automation->status,
            'trigger' => $validated['trigger'] ?? $automation->trigger,
        ])->save();

        return back()->with('success', 'Automation updated.');
    }

    public function destroy(Request $request, Automation $automation): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($automation->organization_id === $organization->id, 404);

        $automation->steps()->delete();
        $automation->delete();

        return redirect()->route('automations')->with('success', 'Automation deleted.');
    }
}
