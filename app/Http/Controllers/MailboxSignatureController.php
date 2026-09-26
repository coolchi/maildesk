<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use App\Support\EmailHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailboxSignatureController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function edit(Request $request): Response|RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $user = $request->user();
        $mailbox = $this->access->mailboxFor($user, $organization);

        if ($mailbox === null) {
            // Owners/admins manage workspace + mailbox signatures under Settings.
            if ($this->access->abilities($user, $organization)['manage']) {
                return redirect()->route('settings', 'signature');
            }

            abort(404, 'You do not have a mailbox signature to edit.');
        }

        return Inertia::render('Mailbox/Signature', [
            'mailbox' => [
                'id' => $mailbox->id,
                'email' => $mailbox->email,
                'display_name' => $mailbox->display_name,
                'signature' => (string) ($mailbox->signature ?? ''),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $mailbox = $this->access->mailboxFor($request->user(), $organization);
        abort_unless($mailbox, 404, 'You do not have a mailbox signature to edit.');

        $validated = $request->validate([
            'signature' => ['nullable', 'string', 'max:20000'],
        ]);

        $html = trim((string) ($validated['signature'] ?? ''));
        $mailbox->forceFill([
            'signature' => trim(strip_tags($html, '<img>')) === ''
                ? null
                : EmailHtmlSanitizer::clean($html),
        ])->save();

        return back()->with('success', 'Signature saved.');
    }
}
