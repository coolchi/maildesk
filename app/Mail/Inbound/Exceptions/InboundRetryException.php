<?php

namespace App\Mail\Inbound\Exceptions;

use RuntimeException;

/**
 * Thrown when an inbound delivery cannot be processed right now but should
 * succeed later (e.g. the provider API is down). The endpoint answers 503 so
 * the provider retries instead of the email being stored incomplete.
 */
class InboundRetryException extends RuntimeException {}
