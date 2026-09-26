<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\Impersonation\ImpersonationDenied;
use App\Services\Impersonation\ImpersonationService;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ImpersonationController extends Controller
{
    public function __construct(public ImpersonationService $impersonation) {}

    public function start(Request $request, User $user): RedirectResponse|SymfonyResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $organization = Organization::query()->findOrFail($validated['organization_id']);

        try {
            $url = $this->impersonation->start($request, $request->user(), $user, $organization, $validated['reason']);
        } catch (ImpersonationDenied $e) {
            $this->impersonation->recordDenied($request, $request->user(), $user, $organization, $e->getMessage());

            return back()->withErrors(['user' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return $this->navigate($request, $url);
    }

    /**
     * Workspace owner/admin "log in as" a teammate in the current organization.
     */
    public function startWorkspace(Request $request, User $user): RedirectResponse|SymfonyResponse
    {
        $organization = CurrentOrganization::from($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $url = $this->impersonation->start($request, $request->user(), $user, $organization, $validated['reason']);
        } catch (ImpersonationDenied $e) {
            $this->impersonation->recordDenied($request, $request->user(), $user, $organization, $e->getMessage());

            return back()->withErrors(['user' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return $this->navigate($request, $url);
    }

    public function leave(Request $request): RedirectResponse|SymfonyResponse
    {
        if (! $this->impersonation->isImpersonating($request)) {
            return redirect()->route('dashboard');
        }

        return $this->navigate($request, $this->impersonation->stop($request, 'left'));
    }

    /**
     * Cross-host navigation: Inertia XHR cannot follow a 302 to another
     * host, so force a full window visit.
     */
    protected function navigate(Request $request, string $url): RedirectResponse|SymfonyResponse
    {
        return $request->header('X-Inertia')
            ? Inertia::location($url)
            : redirect()->away($url);
    }
}
