<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'payload' => ['nullable', 'array'],
            'payload.email' => ['required', 'email', 'max:255'],
        ]);

        $payload = $validated['payload'] ?? [];
        $payload['email'] = Str::lower(trim((string) $payload['email']));

        ProcessAutomationEvent::dispatch(
            $organization->id,
            $validated['name'],
            $payload,
        );

        return response()->json([
            'accepted' => true,
            'status' => 'accepted',
            'event' => $validated['name'],
        ], 202);
    }
}
