<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;

class PlanController extends Controller
{
    /**
     * Delete a catalog plan. Refused while any subscription (in any status)
     * still references it, so billing history keeps its plan.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        $inUse = $plan->subscriptions()->count();

        if ($inUse > 0) {
            return back()->withErrors([
                'plan' => "{$plan->name} is used by {$inUse} subscription(s). Move them to another plan first.",
            ])->with('error', "{$plan->name} is still in use and was not deleted.");
        }

        $name = $plan->name;
        $plan->delete();

        return back()->with('success', "Plan {$name} deleted.");
    }
}
