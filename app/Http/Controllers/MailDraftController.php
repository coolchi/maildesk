<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\MailDraft;
use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailDraftController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);
        $user = $request->user();

        $drafts = MailDraft::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (MailDraft $draft) => $draft->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Drafts/Index', [
            'drafts' => $drafts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $user = $request->user();
        $validated = $this->validated($request);
        $mailbox = $this->resolveMailbox($organization->id, $validated['from'] ?? null)
            ?? $this->access->mailboxFor($user, $organization);

        $draft = MailDraft::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'mailbox_id' => $mailbox?->id,
            'thread_id' => $validated['thread_id'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'html' => $validated['html'] ?? null,
        ]);

        return back()->with([
            'success' => 'Draft saved.',
            'draft_id' => $draft->id,
        ]);
    }

    public function update(Request $request, MailDraft $draft): RedirectResponse
    {
        $this->authorizeDraft($request, $draft);
        $validated = $this->validated($request);
        $mailbox = $this->resolveMailbox($draft->organization_id, $validated['from'] ?? $draft->from);

        $draft->fill([
            'mailbox_id' => $mailbox?->id ?? $draft->mailbox_id,
            'thread_id' => $validated['thread_id'] ?? $draft->thread_id,
            'from' => $validated['from'] ?? $draft->from,
            'to' => $validated['to'] ?? $draft->to,
            'cc' => array_key_exists('cc', $validated) ? $validated['cc'] : $draft->cc,
            'bcc' => array_key_exists('bcc', $validated) ? $validated['bcc'] : $draft->bcc,
            'subject' => array_key_exists('subject', $validated) ? $validated['subject'] : $draft->subject,
            'html' => array_key_exists('html', $validated) ? $validated['html'] : $draft->html,
        ])->save();

        return back()->with([
            'success' => 'Draft saved.',
            'draft_id' => $draft->id,
        ]);
    }

    public function destroy(Request $request, MailDraft $draft): RedirectResponse
    {
        $this->authorizeDraft($request, $draft);
        $draft->delete();

        return back()->with('success', 'Draft deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'string', 'max:255'],
            'to' => ['nullable', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:998'],
            'html' => ['nullable', 'string', 'max:200000'],
            'thread_id' => ['nullable', 'integer'],
        ]);
    }

    protected function authorizeDraft(Request $request, MailDraft $draft): void
    {
        $organization = CurrentOrganization::from($request);
        abort_unless(
            (int) $draft->organization_id === (int) $organization->id
            && (int) $draft->user_id === (int) $request->user()->id,
            404,
        );
    }

    protected function resolveMailbox(int $organizationId, ?string $from): ?Mailbox
    {
        if (! filled($from)) {
            return null;
        }

        $email = strtolower(trim($from));
        if (preg_match('/<(.+)>/', $from, $matches)) {
            $email = strtolower(trim($matches[1]));
        }

        return Mailbox::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();
    }
}
