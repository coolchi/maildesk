<?php

namespace App\Rules;

use App\Services\Webhooks\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects webhook URLs that point at internal/private hosts, or that use
 * plain http:// outside local and testing environments.
 */
class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid endpoint URL.');

            return;
        }

        $error = app(WebhookUrlGuard::class)->check($value);

        if ($error !== null) {
            $fail($error);
        }
    }
}
