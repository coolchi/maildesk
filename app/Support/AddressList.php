<?php

namespace App\Support;

use Closure;

/**
 * Parses a free-text recipient list ("a@x.com, Jane <j@y.com>; b@z.com")
 * into bare addresses, and validates it for form requests.
 */
class AddressList
{
    /**
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        // Split on commas/semicolons that are not inside a quoted display name.
        $parts = preg_split('/[,;](?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];

        return array_values(array_filter(array_map(function (string $part) {
            $part = trim($part);
            if (preg_match('/<([^>]+)>/', $part, $matches)) {
                $part = trim($matches[1]);
            }

            return $part;
        }, $parts), fn (string $email) => $email !== ''));
    }

    /**
     * Validation rule: every entry must be a valid email address.
     */
    public static function rule(int $max = 50): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($max) {
            if ($value === null || $value === '') {
                return;
            }

            $emails = self::parse((string) $value);

            if (count($emails) > $max) {
                $fail("Too many recipients (max {$max}).");

                return;
            }

            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $fail("\"{$email}\" is not a valid email address.");

                    return;
                }
            }
        };
    }
}
