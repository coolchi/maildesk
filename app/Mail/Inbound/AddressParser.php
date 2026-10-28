<?php

namespace App\Mail\Inbound;

use Illuminate\Support\Str;

class AddressParser
{
    /**
     * Parse "Name <email>", "email" or ['email' => ..., 'name' => ...].
     *
     * @return array{email: string, name: ?string}
     */
    public static function one(mixed $value): array
    {
        if (is_array($value)) {
            $email = (string) ($value['email'] ?? $value['address'] ?? '');
            $name = $value['name'] ?? null;

            return ['email' => Str::lower(trim($email)), 'name' => $name ?: null];
        }

        $value = trim((string) $value);

        if (preg_match('/^(.*)<([^>]+)>\s*$/', $value, $m)) {
            $name = trim($m[1], " \t\n\r\0\x0B\"'");

            return ['email' => Str::lower(trim($m[2])), 'name' => $name !== '' ? $name : null];
        }

        return ['email' => Str::lower($value), 'name' => null];
    }

    /**
     * Parse a list (array or comma-separated string) into bare email addresses.
     *
     * @return array<int, string>
     */
    public static function many(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $items = is_array($value) && ! isset($value['email']) && ! isset($value['address'])
            ? $value
            : (is_string($value) ? preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) : [$value]);

        return array_values(array_filter(array_map(
            fn ($item) => self::one($item)['email'],
            $items,
        ), fn (string $email) => str_contains($email, '@')));
    }

    /**
     * Normalise a Message-ID so "<abc@x>" and "abc@x" compare equal.
     */
    public static function messageId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return '<'.trim($value, '<> ').'>';
    }

    /**
     * Parse a References header (space separated ids) or array into ids.
     *
     * Handles: space-separated strings, JSON-encoded arrays, arrays of message IDs.
     * Recursively unpacks nested structures to handle corrupted data (e.g., JSON wrapped in angle brackets).
     *
     * @return array<int, string>
     */
    public static function messageIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::messageIds($decoded);
            }

            if (preg_match('/^<(\[.+\])>$/', $value, $outerMatch)) {
                $innerDecoded = json_decode($outerMatch[1], true);
                if (is_array($innerDecoded)) {
                    return self::messageIds($innerDecoded);
                }
            }

            preg_match_all('/<[^<>]+>/', $value, $matches);
            if ($matches[0] !== []) {
                return self::messageIds($matches[0]);
            }

            $parts = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            $value = $parts ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $decoded = json_decode($item, true);
                if (is_array($decoded)) {
                    $result = array_merge($result, self::messageIds($decoded));

                    continue;
                }

                if (preg_match('/^<(\[.+\])>$/', $item, $outerMatch)) {
                    $innerDecoded = json_decode($outerMatch[1], true);
                    if (is_array($innerDecoded)) {
                        $result = array_merge($result, self::messageIds($innerDecoded));

                        continue;
                    }
                }

                $stripped = trim($item, '<> ');
                if ($stripped !== '' && str_contains($stripped, '@')) {
                    $result[] = '<'.$stripped.'>';
                }
            } elseif (is_array($item)) {
                $result = array_merge($result, self::messageIds($item));
            }
        }

        return array_values(array_unique($result));
    }
}
