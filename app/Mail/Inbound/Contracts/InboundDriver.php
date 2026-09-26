<?php

namespace App\Mail\Inbound\Contracts;

use App\Mail\DTO\InboundEmail;
use App\Mail\Inbound\Exceptions\InboundRetryException;
use Illuminate\Http\Request;

interface InboundDriver
{
    /**
     * Whether the secret needed to authenticate this provider is set.
     * Outside local/testing an unconfigured driver refuses all requests.
     */
    public function isConfigured(): bool;

    /**
     * Confirm the request genuinely came from the provider.
     */
    public function verify(Request $request): bool;

    /**
     * Convert the provider payload into an InboundEmail, or null when the
     * payload is not a received-email event (e.g. a delivery event).
     *
     * @throws InboundRetryException when the provider should retry later
     */
    public function parse(Request $request): ?InboundEmail;
}
