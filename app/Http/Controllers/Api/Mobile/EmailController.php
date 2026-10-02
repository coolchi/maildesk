<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Mail\MailManager;
use App\Models\Organization;
use App\Services\EmailService;
use App\Services\WorkspaceAccess;
use App\Support\AddressList;
use App\Support\DesignTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    /**
     * Send a new email.
     */
    public function store(Request $request, EmailService $emails): JsonResponse
    {
        $organization = $this->organization($request);
        $organization->loadMissing('mailProvider');
        $user = $request->user();

        if (! app(MailManager::class)->canSendFor($organization)) {
            return response()->json([
                'message' => 'Cannot send — this workspace has no active mail provider or SMTP settings.',
            ], 422);
        }

        $validated = $request->validate([
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'string', 'max:2000', AddressList::rule()],
            'subject' => ['required', 'string', 'max:998'],
            'html' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'cc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'bcc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'reply_to' => ['nullable', 'string', 'max:255'],
        ]);

        $fromEmail = $this->extractEmail($validated['from']);
        $domainName = Str::after($fromEmail, '@');

        if (! $this->access->maySendAs($user, $organization, $fromEmail)) {
            return response()->json([
                'message' => 'You can only send from your mailbox address.',
                'errors' => ['from' => ['You can only send from your mailbox address.']],
            ], 422);
        }

        $hasVerifiedDomains = $organization->domains()->where('status', 'verified')->exists();
        $verified = $organization->domains()
            ->where('status', 'verified')
            ->where('name', $domainName)
            ->exists();

        if ($hasVerifiedDomains && ! $verified) {
            return response()->json([
                'message' => 'From address must use a verified domain for this workspace.',
                'errors' => ['from' => ['From address must use a verified domain for this workspace.']],
            ], 422);
        }

        $payload = [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'subject' => $validated['subject'],
            'html' => $validated['html'] ?? null,
            'text' => $validated['text'] ?? strip_tags($validated['html'] ?? ''),
            'design' => DesignTemplates::defaultKey($organization),
            'signature' => true,
        ];

        if (! empty($validated['cc'])) {
            $payload['cc'] = $validated['cc'];
        }

        if (! empty($validated['bcc'])) {
            $payload['bcc'] = $validated['bcc'];
        }

        if (! empty($validated['reply_to'])) {
            $payload['reply_to'] = $validated['reply_to'];
        }

        $message = $emails->send($organization, $payload);

        $status = match ($message->status) {
            'failed' => 'error',
            'suppressed' => 'error',
            default => 'success',
        };

        $error = match ($message->status) {
            'failed' => data_get($message->meta, 'error', 'Provider rejected the message.'),
            'suppressed' => data_get($message->meta, 'error', 'Recipient is suppressed.'),
            default => null,
        };

        return response()->json([
            'status' => $status,
            'message' => $status === 'success' ? 'Email sent.' : $error,
            'email_id' => $message->uuid,
        ], $status === 'error' ? 422 : 201);
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<(.+)>/', $from, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($from));
    }

    /**
     * Get the organization from the request.
     */
    protected function organization(Request $request): Organization
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');

        if (! $workspaceId) {
            abort(400, 'Workspace ID is required. Pass X-Workspace-Id header or workspace_id query parameter.');
        }

        $user = $request->user();
        $organization = $user->organizations()->whereKey($workspaceId)->first();

        if (! $organization) {
            abort(403, 'You do not have access to this workspace.');
        }

        return $organization;
    }
}
