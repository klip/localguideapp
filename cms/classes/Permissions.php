<?php

/**
 * Who may do what in the CMS (docs/auth-cms-plan.md, "Permissions"):
 *
 *   admin    everything, including staff accounts and their roles
 *   manager  everything about app users, disputes, sanctions, bonuses,
 *            refunds, UI text and legal documents — not staff
 *   editor   UI text only
 *
 * Checked by the API (`CmsApi::dispatch()`) for every action; the admin app
 * only uses `capabilitiesOf()` to hide what would be refused.
 */
final class Permissions
{
    private const CAPABILITIES = [
        'admin' => ['staff.manage', 'audit.all', 'users.view', 'users.edit', 'users.freeze', 'disputes.resolve', 'refunds', 'content.edit', 'legal.edit'],
        'manager' => ['audit.operations', 'users.view', 'users.edit', 'users.freeze', 'disputes.resolve', 'refunds', 'content.edit', 'legal.edit'],
        'editor' => ['content.edit'],
    ];

    /** @return string[] */
    public static function capabilitiesOf(string $role): array
    {
        return self::CAPABILITIES[$role] ?? [];
    }

    public static function allows(string $role, ?string $capability): bool
    {
        return $capability === null || in_array($capability, self::capabilitiesOf($role), true);
    }
}
