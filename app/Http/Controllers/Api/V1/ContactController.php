<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $contacts = $organization->contacts()
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
        ]);

        $contact = Contact::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => Str::lower($validated['email']),
            ],
            [
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'meta' => ['status' => 'subscribed'],
            ],
        );

        return response()->json($contact, $contact->wasRecentlyCreated ? 201 : 200);
    }
}
