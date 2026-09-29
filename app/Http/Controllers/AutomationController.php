<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Services\AutomationService;
use App\Services\AutoReplyService;
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
            ->withCount('runs')
            ->latest()
            ->get()
            ->map(fn (Automation $automation) => $automation->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Automations/Index', [
            'automations' => $automations,
            'events' => [],
            'autoReply' => app(AutoReplyService::class)->settings($organization),
        ]);
    }

    public function updateAutoReply(Request $request, AutoReplyService $autoReply): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $autoReply->save($organization, $validated);

        return back()->with('success', 'Auto-reply saved.');
    }

    public function create(): Response
    {
        return Inertia::render('Automations/Show', [
            'id' => null,
            'automation' => null,
        ]);
    }

    public function store(Request $request, AutomationService $automations): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $this->validatedPayload($request);

        $automation = Automation::query()->create([
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'status' => $validated['status'],
            'trigger' => $validated['trigger'],
        ]);

        $automations->syncSteps($automation, $validated['steps']);

        return redirect()
            ->route('automations.show', $automation)
            ->with('success', 'Automation created.');
    }

    public function show(Request $request, Automation $automation): Response
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($automation->organization_id === $organization->id, 404);

        $automation->load('steps')->loadCount('runs');

        return Inertia::render('Automations/Show', [
            'id' => $automation->id,
            'automation' => $automation->toWorkspaceArray(),
        ]);
    }

    public function update(Request $request, Automation $automation, AutomationService $automations): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($automation->organization_id === $organization->id, 404);

        $validated = $this->validatedPayload($request, partial: true);

        $automation->fill([
            'name' => $validated['name'] ?? $automation->name,
            'status' => $validated['status'] ?? $automation->status,
            'trigger' => $validated['trigger'] ?? $automation->trigger,
        ])->save();

        if (array_key_exists('steps', $validated)) {
            $automations->syncSteps($automation, $validated['steps']);
        }

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

    /**
     * @return array{name?: string, status?: string, trigger?: string, steps?: array<int, array{type: string, label?: string, config?: array<string, mixed>}>}
     */
    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:180'],
            'status' => [$partial ? 'sometimes' : 'required', Rule::in(['enabled', 'disabled', 'active', 'paused'])],
            'trigger' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'steps' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'steps.*.type' => ['required_with:steps', 'string', Rule::in(['trigger', 'delay', 'email'])],
            'steps.*.label' => ['nullable', 'string', 'max:255'],
            'steps.*.config' => ['nullable', 'array'],
        ];

        $validated = $request->validate($rules);

        if (isset($validated['status'])) {
            $validated['status'] = match ($validated['status']) {
                'enabled', 'active' => 'active',
                default => 'paused',
            };
        }

        return $validated;
    }
}
