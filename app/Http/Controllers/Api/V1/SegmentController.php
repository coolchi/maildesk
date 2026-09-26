<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $segments = $organization->segments()
            ->withCount('contacts')
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json($segments);
    }
}
