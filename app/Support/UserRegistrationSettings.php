<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Workspace settings for public student/staff self-registration.
 *
 * @phpstan-type Settings array{
 *     enabled: bool,
 *     approval: string,
 *     default_role: string,
 *     default_inbox: bool,
 *     default_transactional: bool,
 *     default_marketing: bool
 * }
 */
class UserRegistrationSettings
{
    public const APPROVAL_AUTO = 'auto';

    public const APPROVAL_MANUAL = 'manual';

    /**
     * @return Settings
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'approval' => self::APPROVAL_AUTO,
            'default_role' => 'staff',
            'default_inbox' => true,
            'default_transactional' => false,
            'default_marketing' => false,
        ];
    }

    /**
     * @return Settings
     */
    public static function for(Organization $organization): array
    {
        $stored = $organization->settings['user_registration'] ?? [];

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function enabled(Organization $organization): bool
    {
        return (bool) self::for($organization)['enabled'];
    }

    public static function requiresApproval(Organization $organization): bool
    {
        return self::for($organization)['approval'] === self::APPROVAL_MANUAL;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Settings
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();

        $approval = ($input['approval'] ?? $defaults['approval']) === self::APPROVAL_MANUAL
            ? self::APPROVAL_MANUAL
            : self::APPROVAL_AUTO;

        $role = in_array($input['default_role'] ?? '', ['admin', 'developer', 'staff'], true)
            ? $input['default_role']
            : $defaults['default_role'];

        return [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'approval' => $approval,
            'default_role' => $role,
            'default_inbox' => (bool) ($input['default_inbox'] ?? true),
            'default_transactional' => (bool) ($input['default_transactional'] ?? false),
            'default_marketing' => (bool) ($input['default_marketing'] ?? false),
        ];
    }
}
