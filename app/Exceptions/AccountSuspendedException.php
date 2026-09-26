<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a suspended or closed account tries to send mail.
 * API callers get the standard {"message": "..."} error with HTTP 403.
 */
class AccountSuspendedException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        return back()
            ->with('error', $this->getMessage())
            ->withErrors(['account' => $this->getMessage()]);
    }
}
