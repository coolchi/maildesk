<?php

namespace App\Services\Providers;

use App\Mail\MailManager;
use App\Models\MailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live, read-only "test connection" for a platform mail provider.
 *
 *  - resend: one authenticated GET /domains (the endpoint ResendDomainClient
 *    already uses). 2xx = OK, 401/403 = invalid key, anything else = failure.
 *  - smtp:   connect + EHLO + STARTTLS/TLS + AUTH, then QUIT. No message.
 *  - others: honestly reported as not supported.
 *
 * Stores nothing. Results never contain credentials: every message is
 * scrubbed of the provider's secret values before it is returned.
 */
class ProviderConnectionTester
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(
        protected MailManager $mail,
        protected SmtpConnector $smtp,
    ) {}

    /**
     * @return array{ok: bool, message: string, latency_ms: int|null, driver: string}
     */
    public function test(MailProvider $provider): array
    {
        $driver = strtolower((string) ($provider->driver ?: $provider->type));
        $started = hrtime(true);

        try {
            [$ok, $message] = match (true) {
                $driver === 'resend' => $this->testResend($provider),
                $driver === 'smtp' || $provider->type === 'smtp' => $this->testSmtp($provider),
                default => [null, "Test connection is not supported for the \"{$driver}\" driver yet."],
            };
        } catch (Throwable $e) {
            [$ok, $message] = [false, 'Connection failed: '.$e->getMessage()];
        }

        $latency = $ok === null ? null : (int) round((hrtime(true) - $started) / 1_000_000);

        $result = [
            'ok' => $ok === true,
            'message' => $this->scrub($message, $provider),
            'latency_ms' => $latency,
            'driver' => $driver,
        ];

        Log::info('Mail provider connection test', [
            'provider_id' => $provider->id,
            'driver' => $driver,
            'ok' => $result['ok'],
            'latency_ms' => $latency,
        ]);

        return $result;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function testResend(MailProvider $provider): array
    {
        $key = (string) ($this->configValue($provider, ['API_KEY']) ?? '');

        if ($key === '') {
            return [false, 'No API key is stored for this provider.'];
        }

        // Fixed, configured endpoint (never the editable api_base) so the test can't be pointed elsewhere.
        $base = rtrim((string) config('maildesk.inbound.resend_api_url', 'https://api.resend.com'), '/');

        try {
            $response = Http::baseUrl($base)
                ->withToken($key)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->get('/domains');
        } catch (ConnectionException $e) {
            return [false, 'Could not reach Resend: '.$e->getMessage()];
        }

        if ($response->successful()) {
            return [true, 'Connected to Resend; the API key is valid.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [false, "Resend rejected the API key (HTTP {$response->status()})."];
        }

        return [false, "Resend returned HTTP {$response->status()}."];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function testSmtp(MailProvider $provider): array
    {
        // Same resolution as sending: admin config over MAILDESK_SMTP_* env.
        $config = $this->mail->smtpConfigFor(null, $provider);

        if (trim((string) ($config['host'] ?? '')) === '') {
            return [false, 'No SMTP host is configured for this provider.'];
        }

        $this->smtp->check($config, self::TIMEOUT_SECONDS);

        $auth = filled($config['username'] ?? null) ? ' and authenticated' : '';

        return [true, "Connected to {$config['host']}:".($config['port'] ?? '')."{$auth}; no message was sent."];
    }

    /**
     * @param  list<string>  $keys
     */
    protected function configValue(MailProvider $provider, array $keys): ?string
    {
        foreach ($provider->config ?? [] as $row) {
            if (in_array(strtoupper((string) ($row['key'] ?? '')), $keys, true)) {
                $value = (string) ($row['value'] ?? '');

                return $value === '' || str_contains($value, '•') ? null : $value;
            }
        }

        return null;
    }

    /**
     * Remove every stored secret (and the SMTP password / API key resolved
     * from env) plus common key shapes from a message.
     */
    public function scrub(string $message, MailProvider $provider): string
    {
        $secrets = [];

        foreach ($provider->config ?? [] as $row) {
            $value = (string) ($row['value'] ?? '');
            $isSecretKey = (bool) preg_match('/KEY|SECRET|TOKEN|PASS/i', (string) ($row['key'] ?? ''));

            if ($value !== '' && (($row['secret'] ?? false) || $isSecretKey)) {
                $secrets[] = $value;
            }
        }

        foreach (['password', 'api_key'] as $field) {
            $value = (string) (config("maildesk.providers.smtp.{$field}") ?? config("maildesk.providers.resend.{$field}") ?? '');
            if ($value !== '') {
                $secrets[] = $value;
            }
        }

        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach (array_unique($secrets) as $secret) {
            if (strlen($secret) >= 3) {
                $message = str_replace($secret, '[redacted]', $message);
                $message = str_replace(base64_encode($secret), '[redacted]', $message);
            }
        }

        // Common credential shapes, in case a library echoes one back.
        $message = (string) preg_replace('/\b(re_[A-Za-z0-9_]{6,}|SG\.[A-Za-z0-9_.-]{10,}|AKIA[0-9A-Z]{12,})\b/', '[redacted]', $message);
        $message = (string) preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);

        return mb_substr($message, 0, 300);
    }
}
