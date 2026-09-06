<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return response()->json($organization->domains()->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $domain = Domain::query()->create([
            'organization_id' => $organization->id,
            'name' => strtolower($validated['name']),
            'status' => 'pending',
            'provider' => $organization->default_provider,
            'dns_records' => Domain::defaultDnsRecords(strtolower($validated['name'])),
        ]);

        return response()->json($domain, 201);
    }
}
