<?php

namespace App\Http\Controllers;

use App\Models\BroadcastRecipient;
use App\Services\BroadcastService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public unsubscribe endpoint for broadcast emails. Routes are guarded by
 * a signed URL, so no login is needed. GET shows a confirm button (so link
 * scanners can't unsubscribe people); POST unsubscribes, which is also the
 * RFC 8058 one-click target named in the List-Unsubscribe header.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, BroadcastRecipient $recipient): Response
    {
        return response()->view('unsubscribe', [
            'email' => $recipient->email,
            'done' => $recipient->unsubscribed_at !== null,
            'action' => $request->fullUrl(),
            ...$this->branding($recipient),
        ]);
    }

    public function store(Request $request, BroadcastRecipient $recipient, BroadcastService $broadcasts): Response
    {
        $broadcasts->unsubscribe($recipient);

        return response()->view('unsubscribe', [
            'email' => $recipient->email,
            'done' => true,
            'action' => null,
            ...$this->branding($recipient),
        ]);
    }

    /**
     * Page text and colour from the workspace's Settings, Unsubscribe page.
     *
     * @return array<string, mixed>
     */
    protected function branding(BroadcastRecipient $recipient): array
    {
        $organization = $recipient->broadcast->organization;
        $saved = (array) (($organization->settings ?? [])['unsubscribe'] ?? []);
        $accent = (string) ($saved['accent'] ?? '');

        return [
            'organization' => ($saved['brand'] ?? '') ?: $organization->name,
            'headline' => ($saved['headline'] ?? '') ?: "You're unsubscribed",
            'message' => $saved['message'] ?? null,
            'footer' => $saved['footer'] ?? null,
            'preferencesUrl' => ! empty($saved['showPreferences']) && filter_var($saved['preferencesUrl'] ?? '', FILTER_VALIDATE_URL)
                ? $saved['preferencesUrl'] : null,
            'buttonLabel' => ($saved['buttonLabel'] ?? '') ?: 'Manage preferences',
            // Only a plain hex colour is allowed into the inline style.
            'accent' => preg_match('/^#[0-9a-f]{3,8}$/i', $accent) ? $accent : '#22d3ee',
        ];
    }
}
