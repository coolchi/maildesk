<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Segment;
use App\Services\SegmentMembership;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SegmentController extends Controller
{
    public function store(Request $request, SegmentMembership $membership): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $validated = $this->validateSegment($request, $membership);

        $segment = Segment::query()->create([
            'organization_id' => $organization->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'rules' => $validated['rules'] ?? null,
        ]);

        if (! empty($validated['contact_ids'])) {
            $ids = $this->ownedContactIds($organization->id, $validated['contact_ids']);
            $segment->contacts()->syncWithoutDetaching($ids);
        }

        return back()->with('success', 'Segment created.');
    }

    public function update(Request $request, Segment $segment, SegmentMembership $membership): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($segment->organization_id === $organization->id, 404);

        $validated = $this->validateSegment($request, $membership);

        $segment->forceFill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'rules' => $validated['rules'] ?? null,
        ])->save();

        if (array_key_exists('contact_ids', $validated)) {
            $ids = $this->ownedContactIds($organization->id, $validated['contact_ids'] ?? []);
            $segment->contacts()->sync($ids);
        }

        return back()->with('success', 'Segment saved.');
    }

    public function destroy(Request $request, Segment $segment): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($segment->organization_id === $organization->id, 404);

        $segment->delete();

        return back()->with('success', 'Segment deleted.');
    }

    public function attach(Request $request, Segment $segment): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($segment->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
        ]);

        $ids = $this->ownedContactIds($organization->id, $validated['contact_ids']);
        abort_if($ids === [], 422);

        $segment->contacts()->syncWithoutDetaching($ids);

        return back()->with('success', count($ids) === 1 ? 'Contact added to segment.' : 'Contacts added to segment.');
    }

    public function detach(Request $request, Segment $segment, Contact $contact): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        abort_unless($segment->organization_id === $organization->id, 404);
        abort_unless($contact->organization_id === $organization->id, 404);

        $segment->contacts()->detach($contact->id);

        return back()->with('success', 'Contact removed from segment.');
    }

    /**
     * @return array{name: string, description?: string|null, rules?: array<int, array<string, string>>|null, contact_ids?: array<int, int>}
     */
    protected function validateSegment(Request $request, SegmentMembership $membership): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'rules' => ['nullable', 'array'],
            'rules.*.field' => ['required_with:rules', 'string', Rule::in(['meta.status', 'status', 'email_domain'])],
            'rules.*.op' => ['nullable', 'string', Rule::in(['eq', 'equals', 'contains'])],
            'rules.*.operator' => ['nullable', 'string', Rule::in(['eq', 'equals', 'contains'])],
            'rules.*.value' => ['required_with:rules', 'string', 'max:255'],
            'contact_ids' => ['sometimes', 'nullable', 'array'],
            'contact_ids.*' => ['integer'],
        ]);

        if (array_key_exists('rules', $validated)) {
            $normalized = $membership->normalizeRules($validated['rules'] ?? []);
            $validated['rules'] = $normalized === [] ? null : $normalized;
        }

        return $validated;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    protected function ownedContactIds(int $organizationId, array $ids): array
    {
        return Contact::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
