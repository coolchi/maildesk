<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function threads(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $threads = $organization->threads()
            ->with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->latest('last_message_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json($threads);
    }

    public function showThread(Request $request, int $thread): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        /** @var Thread $model */
        $model = $organization->threads()
            ->with(['messages' => fn ($q) => $q->orderBy('created_at')])
            ->findOrFail($thread);

        return response()->json($model);
    }
}
