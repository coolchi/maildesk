<?php

namespace App\Mail\Events\Contracts;

use App\Mail\Events\DeliveryEvent;
use Illuminate\Http\Request;

/**
 * One implementation per mail provider, mirroring InboundDriver.
 */
interface DeliveryEventDriver
{
    /**
     * Whether the secret needed to authenticate this provider is set.
     */
    public function isConfigured(): bool;

    /**
     * Confirm the request genuinely came from the provider.
     */
    public function verify(Request $request): bool;

    /**
     * Convert the provider payload into a DeliveryEvent, or null when the
     * event type is not one MailDesk handles.
     */
    public function parse(Request $request): ?DeliveryEvent;
}
