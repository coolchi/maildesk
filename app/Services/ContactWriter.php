<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Str;

class ContactWriter
{
    /**
     * @return array{0: ?string, 1: ?string}
     */
    public function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [
            $parts[0] !== '' ? $parts[0] : null,
            isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null,
        ];
    }

    public function upsert(
        Organization $organization,
        string $email,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $company = null,
        ?string $name = null,
    ): Contact {
        if ($name !== null && $name !== '') {
            [$parsedFirst, $parsedLast] = $this->splitName($name);
            $firstName = $firstName ?: $parsedFirst;
            $lastName = $lastName ?: $parsedLast;
        }

        $email = Str::lower(trim($email));
        $contact = Contact::query()->firstOrNew([
            'organization_id' => $organization->id,
            'email' => $email,
        ]);

        if ($firstName !== null && $firstName !== '') {
            $contact->first_name = $firstName;
        }

        if ($lastName !== null && $lastName !== '') {
            $contact->last_name = $lastName;
        }

        if ($company !== null) {
            $contact->company = trim($company) !== '' ? trim($company) : null;
        }

        if (! $contact->exists) {
            $contact->meta = ['status' => 'subscribed'];
            $contact->unsubscribed_at = null;
        }

        $contact->save();

        return $contact;
    }
}
