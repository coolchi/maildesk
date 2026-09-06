<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $logs = $organization->messages()
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Message $message) => $this->toLogEntry($message))
            ->values()
            ->all();

        return Inertia::render('Logs/Index', [
            'logs' => $logs,
        ]);
    }

    /**
     * @return array{id: int|string, level: string, event: string, message: string, time: string}
     */
    private function toLogEntry(Message $message): array
    {
        $level = match ($message->status) {
            'failed', 'bounced' => $message->status === 'failed' ? 'error' : 'warn',
            'complained' => 'warn',
            'suppressed' => 'warn',
            default => 'info',
        };

        $event = match ($message->direction) {
            'inbound' => 'email.received',
            default => match ($message->status) {
                'delivered' => 'email.delivered',
                'bounced' => 'email.bounced',
                'failed' => 'email.failed',
                'scheduled' => 'email.scheduled',
                'suppressed' => 'email.suppressed',
                'sent', 'queued' => 'email.sent',
                default => 'email.'.$message->status,
            },
        };

        $to = is_array($message->to) ? ($message->to[0]['email'] ?? $message->to[0] ?? '') : (string) $message->to;

        return [
            'id' => $message->id,
            'level' => $level,
            'event' => $event,
            'message' => trim(($message->subject ?: 'Message').' → '.$to),
            'time' => ($message->sent_at ?? $message->created_at)?->diffForHumans() ?? '',
        ];
    }
}
