<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuppressionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $suppressions = $organization->suppressions()
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json($suppressions);
    }
}
