<?php

namespace App\Services\Providers;

use App\Mail\Providers\SmtpProvider;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Opens an SMTP session (connect, EHLO, STARTTLS/implicit TLS, AUTH) with the
 * same transport SmtpProvider sends with, then QUITs. Never sends a message.
 * Tests bind a fake in the container so nothing touches the network.
 */
class SmtpConnector
{
    /**
     * @param  array<string, mixed>  $config  host, port, username, password, encryption
     *
     * @throws \Throwable on connection / TLS / authentication failure
     */
    public function check(array $config, float $timeoutSeconds = 10.0): void
    {
        $transport = SmtpProvider::makeTransport($config);

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout($timeoutSeconds);
        }

        try {
            $transport->start();
        } finally {
            $transport->stop();
        }
    }
}
