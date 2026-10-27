<?php

namespace App\Services\Imap;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MailboxProvisioner
{
    public function __construct(protected StalwartClient $client) {}

    public function enabled(): bool
    {
        return $this->client->enabled() && (bool) config('maildesk.stalwart.auto_provision', true);
    }

    /**
     * Ensure the mailbox exists in Stalwart and has an app password.
     * Returns the plaintext app password when a new one was generated.
     */
    public function provision(Mailbox $mailbox, ?string $password = null, bool $forceEnable = true): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($mailbox->status !== 'active' && ! $forceEnable) {
            return null;
        }

        $plain = $password;
        $generated = false;
        if (! filled($plain)) {
            if (filled($mailbox->imap_password)) {
                $plain = $mailbox->imap_password;
            } else {
                $plain = $this->generateAppPassword();
                $generated = true;
            }
        }

        try {
            $ids = $this->client->upsertMailboxUser(
                $mailbox->email,
                $plain,
                $mailbox->stalwart_account_id,
            );

            $mailbox->forceFill([
                'imap_enabled' => true,
                'stalwart_account_id' => $ids['id'],
                'stalwart_domain_id' => $ids['domain_id'],
                'imap_password' => $plain,
            ])->save();

            return $generated || $password !== null ? $plain : null;
        } catch (Throwable $e) {
            Log::warning('Stalwart mailbox provision failed', [
                'mailbox' => $mailbox->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function rotateAppPassword(Mailbox $mailbox): string
    {
        $plain = $this->generateAppPassword();
        $this->provision($mailbox, $plain, forceEnable: true);

        return $plain;
    }

    public function disable(Mailbox $mailbox, bool $deleteRemote = false): void
    {
        if ($deleteRemote && $this->client->enabled()) {
            try {
                $this->client->deleteAccount($mailbox->stalwart_account_id);
            } catch (Throwable $e) {
                Log::warning('Stalwart mailbox delete failed', [
                    'mailbox' => $mailbox->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $mailbox->forceFill([
            'imap_enabled' => false,
            'stalwart_account_id' => $deleteRemote ? null : $mailbox->stalwart_account_id,
            'imap_password' => null,
            'imap_last_uid' => null,
            'imap_uidvalidity' => null,
        ])->save();
    }

    public function destroyRemote(Mailbox $mailbox): void
    {
        if (! $this->client->enabled()) {
            return;
        }

        try {
            $this->client->deleteAccount($mailbox->stalwart_account_id);
        } catch (Throwable $e) {
            Log::warning('Stalwart mailbox destroy failed', [
                'mailbox' => $mailbox->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function generateAppPassword(): string
    {
        return 'md_'.Str::password(20, letters: true, numbers: true, symbols: false);
    }
}
