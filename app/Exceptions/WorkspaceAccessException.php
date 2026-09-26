<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a workspace cannot use the product (expired trial / past due)
 * or cannot send (quota). API callers get HTTP 403 JSON.
 */
class WorkspaceAccessException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        if ($request->routeIs('settings*', 'billing*', 'profile*', 'logout')) {
            return back()
                ->with('error', $this->getMessage())
                ->withErrors(['account' => $this->getMessage()]);
        }

        return redirect()
            ->route('settings', 'billing')
            ->with('error', $this->getMessage())
            ->withErrors(['account' => $this->getMessage()]);
    }
}
