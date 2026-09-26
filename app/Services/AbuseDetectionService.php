<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;

class AbuseDetectionService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('abuse_detection') && $this->ai->configured();
    }

    /**
     * Heuristic scan of recent sending / bounce / webhook health, with optional AI narrative.
     *
     * @return array{
     *     severity: string,
     *     flags: list<array{code: string, label: string, detail: string}>,
     *     summary: string,
     *     scanned_at: string
     * }
     */
    public function scan(Organization $organization): array
    {
        $since = now()->subDay();
        $outbound = $organization->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since);

        $sent = (clone $outbound)->count();
        $bounced = (clone $outbound)->where('status', 'bounced')->count();
        $complained = (clone $outbound)->where('status', 'complained')->count();
        $failed = (clone $outbound)->where('status', 'failed')->count();

        $bounceRate = $sent > 0 ? round(($bounced / $sent) * 100, 1) : 0.0;
        $complaintRate = $sent > 0 ? round(($complained / $sent) * 100, 2) : 0.0;

        $webhookFailures = WebhookDelivery::query()
            ->whereHas('webhook', fn ($q) => $q->where('organization_id', $organization->id))
            ->where('created_at', '>=', $since)
            ->where('status', 'failed')
            ->count();

        $flags = [];

        if ($sent >= 50 && $bounceRate >= 8) {
            $flags[] = [
                'code' => 'bounce_spike',
                'label' => 'High bounce rate',
                'detail' => "{$bounceRate}% of {$sent} outbound messages bounced in the last 24h.",
            ];
        }

        if ($sent >= 20 && $complaintRate >= 0.3) {
            $flags[] = [
                'code' => 'complaint_spike',
                'label' => 'Complaint spike',
                'detail' => "{$complaintRate}% complaint rate on {$sent} sends in the last 24h.",
            ];
        }

        if ($sent >= 200) {
            $flags[] = [
                'code' => 'volume_spike',
                'label' => 'Volume spike',
                'detail' => "{$sent} outbound messages in the last 24h.",
            ];
        }

        if ($webhookFailures >= 10) {
            $flags[] = [
                'code' => 'webhook_failures',
                'label' => 'Webhook failure storm',
                'detail' => "{$webhookFailures} failed webhook deliveries in the last 24h.",
            ];
        }

        if ($failed >= 25 && $sent > 0 && ($failed / $sent) >= 0.2) {
            $flags[] = [
                'code' => 'send_failures',
                'label' => 'Send failure cluster',
                'detail' => "{$failed} failed sends out of {$sent} in the last 24h.",
            ];
        }

        $severity = match (true) {
            count($flags) >= 3 => 'critical',
            count($flags) === 2 => 'high',
            count($flags) === 1 => 'medium',
            default => 'ok',
        };

        $summary = $flags === []
            ? 'No abuse signals detected in the last 24 hours.'
            : collect($flags)->pluck('label')->implode('; ').'.';

        if ($this->enabled() && $flags !== []) {
            $summary = $this->narrative($organization, $flags, $summary) ?: $summary;
        }

        $result = [
            'severity' => $severity,
            'flags' => $flags,
            'summary' => $summary,
            'scanned_at' => Carbon::now()->toIso8601String(),
            'stats' => [
                'sent' => $sent,
                'bounced' => $bounced,
                'complained' => $complained,
                'failed' => $failed,
                'bounce_rate' => $bounceRate,
                'complaint_rate' => $complaintRate,
                'webhook_failures' => $webhookFailures,
            ],
        ];

        $settings = $organization->settings ?? [];
        $settings['abuse'] = [
            'severity' => $result['severity'],
            'flags' => $result['flags'],
            'summary' => $result['summary'],
            'scanned_at' => $result['scanned_at'],
            'stats' => $result['stats'],
        ];
        $organization->forceFill(['settings' => $settings])->save();

        return $result;
    }

    /**
     * @param  list<array{code: string, label: string, detail: string}>  $flags
     */
    protected function narrative(Organization $organization, array $flags, string $fallback): ?string
    {
        try {
            $response = $this->ai->driver()->chat(new AiChatRequest(
                messages: [
                    [
                        'role' => 'system',
                        'content' => 'You write a one-sentence abuse/risk summary for email platform operators. Plain text only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Workspace: {$organization->name}\nFlags JSON:\n".json_encode($flags),
                    ],
                ],
                temperature: 0.2,
                maxTokens: 120,
            ));

            $text = trim($response->content);

            return $text !== '' ? mb_substr($text, 0, 280) : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
