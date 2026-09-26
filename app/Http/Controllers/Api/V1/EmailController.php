<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Message;
use App\Models\Organization;
use App\Services\EmailService;
use App\Services\SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailController extends Controller
{
    /** Internal fields never returned by the API. */
    public const HIDDEN_FIELDS = ['meta', 'bcc', 'headers'];

    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $messages = $organization->messages()
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        $messages->getCollection()->each(fn (Message $message) => $message->makeHidden(self::HIDDEN_FIELDS));

        return response()->json($messages);
    }

    public function store(Request $request, EmailService $emails): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $validated = $request->validate([
            'from' => ['required'],
            'to' => ['required'],
            'subject' => ['required', 'string', 'max:998'],
            'html' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'cc' => ['nullable'],
            'bcc' => ['nullable'],
            'reply_to' => ['nullable'],
            'tags' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'signature' => ['nullable', 'boolean'],
        ]);

        $fromEmail = $this->senderEmail($validated['from']);
        if ($fromEmail === null) {
            throw ValidationException::withMessages([
                'from' => 'The from field must be an email address, "Name <email>", or {"email": ..., "name": ...}.',
            ]);
        }
        $fromDomain = Str::lower(Str::after($fromEmail, '@'));

        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $scopedDomain = $apiKey?->scopedDomain();

        if ($scopedDomain !== null && $fromDomain !== $scopedDomain) {
            return response()->json([
                'message' => "This API key can only send from {$scopedDomain}.",
            ], 403);
        }

        $verified = $organization->domains()
            ->where('status', 'verified')
            ->whereRaw('lower(name) = ?', [$fromDomain])
            ->exists();

        if (! $verified) {
            throw ValidationException::withMessages([
                'from' => "The sender domain {$fromDomain} is not verified for this workspace.",
            ]);
        }

        // Signatures are opt-in for API sends: per request, or via the
        // organization's "add to API emails" setting.
        $validated['signature'] = $request->has('signature')
            ? $request->boolean('signature')
            : app(SignatureService::class)->settings($organization)['api'];

        $message = $emails->send($organization, $validated);

        return response()->json([
            'id' => $message->uuid,
            'status' => $message->status,
            'provider' => $message->provider,
            'provider_message_id' => $message->provider_message_id,
            'created_at' => $message->created_at,
        ], $message->status === 'sent' ? 201 : 422);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        /** @var Message $message */
        $message = $organization->messages()->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'id' => $message->uuid,
            'direction' => $message->direction,
            'status' => $message->status,
            'from' => [
                'email' => $message->from_email,
                'name' => $message->from_name,
            ],
            'to' => $message->to,
            'cc' => $message->cc,
            'subject' => $message->subject,
            'html' => $message->html_body,
            'text' => $message->text_body,
            'provider' => $message->provider,
            'provider_message_id' => $message->provider_message_id,
            'created_at' => $message->created_at,
            'sent_at' => $message->sent_at,
        ]);
    }

    private function senderEmail(mixed $from): ?string
    {
        $email = is_array($from) ? ($from['email'] ?? null) : $from;

        if (! is_string($email)) {
            return null;
        }

        if (preg_match('/<([^>]+)>\s*$/', $email, $matches)) {
            $email = $matches[1];
        }

        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
