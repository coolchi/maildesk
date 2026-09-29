<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\ContactWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());

        $contacts = $this->organization($request)->contacts()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.strtolower($search).'%';
                $query->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(email) like ?', [$like])
                        ->orWhereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like]);
                });
            })
            ->orderBy('email')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $contacts->map(fn (Contact $contact) => $this->payload($contact))->values(),
        ]);
    }

    public function store(Request $request, ContactWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
        ]);

        $contact = $writer->upsert(
            $this->organization($request),
            $validated['email'],
            company: $validated['company'] ?? null,
            name: $validated['name'] ?? null,
        );

        return response()->json(['data' => $this->payload($contact)], 201);
    }

    /**
     * @return array{id: int, email: string, name: string, company: string|null}
     */
    private function payload(Contact $contact): array
    {
        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        return [
            'id' => $contact->id,
            'email' => $contact->email,
            'name' => $name !== '' ? $name : $contact->email,
            'company' => $contact->company,
        ];
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return $organization;
    }
}
