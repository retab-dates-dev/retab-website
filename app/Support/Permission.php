<?php

namespace App\Support;

/**
 * The admin-panel permission catalogue. A permission is "section.action"
 * (e.g. "orders.export"). Admins bypass all checks; editors are granted a
 * subset per user (stored in users.permissions, defaulting to DEFAULTS).
 *
 * Drives three things: the RequirePermission route middleware, the sidebar
 * visibility (a section is shown when the user has "<section>.view"), and the
 * admin Authorization grid.
 */
class Permission
{
    /** Sections → the actions that can be granted. */
    public const SCHEMA = [
        'orders' => ['view', 'manage', 'export'],
        'products' => ['view', 'create', 'edit', 'delete'],
        'product_requests' => ['view', 'manage'],
        'inventory' => ['view', 'import'],
        'returns' => ['view', 'resolve'],
        'shipping' => ['view', 'manage'],
        'customers' => ['view'],
        'marketing' => ['view', 'send'],
        'coupons' => ['view', 'create', 'edit', 'delete'],
        'discounts' => ['view', 'manage'],
        'store_events' => ['view', 'manage'],
        'reviews' => ['view', 'manage'],
        'product_reviews' => ['view', 'manage'],
        'content_pages' => ['view', 'edit'],
        'contact_messages' => ['view', 'manage'],
        'settings' => ['view', 'edit'],
        'change_log' => ['view', 'revert'],
        // Read the staff directory, and set another staff member's password
        // without knowing the old one. See DEFAULTS for why both start denied,
        // and Admin\UserController::resetPassword for the escalation guard.
        'staff' => ['view', 'reset_password'],
    ];

    /**
     * Default permissions for a new editor: day-to-day operational access, but
     * NOT the sensitive / irreversible actions (order export, product delete,
     * marketing send, settings edit, change-log revert) — the admin grants those.
     */
    public const DEFAULTS = [
        'orders' => ['view' => true, 'manage' => true, 'export' => false],
        'products' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'product_requests' => ['view' => true, 'manage' => true],
        'inventory' => ['view' => true, 'import' => true],
        'returns' => ['view' => true, 'resolve' => true],
        'shipping' => ['view' => true, 'manage' => false],
        'customers' => ['view' => true],
        'marketing' => ['view' => true, 'send' => false],
        'coupons' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'discounts' => ['view' => true, 'manage' => false],
        'store_events' => ['view' => true, 'manage' => false],
        'reviews' => ['view' => true, 'manage' => true],
        'product_reviews' => ['view' => true, 'manage' => true],
        'content_pages' => ['view' => true, 'edit' => true],
        'contact_messages' => ['view' => true, 'manage' => true],
        'settings' => ['view' => false, 'edit' => false],
        'change_log' => ['view' => true, 'revert' => false],
        // 🔑 Both DENIED by default, and deliberately absent from every preset
        // below. Setting a colleague's password is a trust-level action, not an
        // operational one — an admin turns it on per person, on purpose.
        'staff' => ['view' => false, 'reset_password' => false],
    ];

    /**
     * Named starting points for the permission grid, by SECTION.
     *
     * Several dozen switches is a lot to set one at a time, and the
     * realistic staff roles are few: someone who runs the daily order desk, someone
     * who looks after the catalogue, a trusted second-in-command, or a read-only
     * account for a bookkeeper. Presets are a starting point the admin then
     * fine-tunes — they are applied client-side to the grid, never stored, so the
     * saved value is always the explicit map the admin actually confirmed.
     *
     * Listing SECTIONS rather than individual permissions is deliberate: a new
     * action added to SCHEMA is then included automatically instead of being
     * silently omitted from every preset until someone remembers to update them.
     *
     * @var array<string, list<string>>
     */
    public const PRESETS = [
        // The daily fulfilment desk: take orders out of the door, handle returns.
        'operations' => ['orders', 'returns', 'shipping', 'product_requests', 'customers', 'inventory', 'contact_messages'],
        // Looks after what the store sells and how it reads.
        'catalogue' => ['products', 'coupons', 'discounts', 'store_events', 'reviews', 'product_reviews', 'content_pages'],
        // Everything except the settings that can reconfigure the business itself.
        'manager' => [
            'orders', 'returns', 'shipping', 'product_requests', 'customers', 'inventory', 'contact_messages',
            'products', 'coupons', 'discounts', 'store_events', 'reviews', 'product_reviews', 'content_pages', 'marketing', 'change_log',
        ],
    ];

    /**
     * Expand a preset into a complete permission map.
     *
     * Every section in SCHEMA is present in the result, so a section the preset
     * does not name is explicitly denied rather than absent — which matters
     * because `resolvedPermissions()` falls back to DEFAULTS for a missing
     * section, and an omitted key would silently grant instead of deny.
     *
     * `viewOnly` grants the `view` action alone on the named sections, which is
     * what a bookkeeper or a reporting account wants.
     *
     * @return array<string, array<string, bool>>
     */
    public static function preset(string $name, bool $viewOnly = false): array
    {
        $sections = $name === 'full' ? array_keys(self::SCHEMA) : (self::PRESETS[$name] ?? []);

        $map = [];
        foreach (self::SCHEMA as $section => $actions) {
            $granted = in_array($section, $sections, true);
            foreach ($actions as $action) {
                $map[$section][$action] = $granted && (! $viewOnly || $action === 'view');
            }
        }

        return $map;
    }
}
