<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $members = $organization->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);

        return response()->json([
            'data' => $members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot?->role,
            ])->values(),
        ]);
    }
}
