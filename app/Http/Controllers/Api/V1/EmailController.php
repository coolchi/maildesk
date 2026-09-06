<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Organization;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $messages = $organization->messages()
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 25), 100));

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
        ]);

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
}
