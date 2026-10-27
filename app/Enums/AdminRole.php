<?php

namespace App\Enums;

/**
 * The back-office roles and what each of them is allowed to touch.
 *
 * This enum is the single source of truth for the permission map. The
 * server enforces it through App\Http\Middleware\EnsureAdminPermission
 * on every /admin route, and the admin UI reads the very same map to
 * draw its role chips — so a role can never be described one way in the
 * interface and enforced another way on the server.
 *
 * Access is expressed per module with two levels:
 *
 *   0 — none    the module is not listed for the role at all
 *   1 — view    the role may open the module's screens
 *   2 — manage  the role may also change the module's rows
 *
 * A write request (POST / PUT / PATCH / DELETE) needs level 2; a read
 * request needs level 1. The levels below therefore also decide who may
 * administer the back office itself: only the owner reaches level 2 on
 * the `admins` and `settings` modules, so creating, editing, suspending
 * or deleting an account — and changing any setting — stays with them.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case OrdersStaff = 'orders_staff';
    case ContentEditor = 'content_editor';
    case Viewer = 'viewer';

    /**
     * Access levels for a role, keyed by module.
     *
     * @var array<string, array<string, int>>
     */
    private const MATRIX = [
        'owner' => [
            'dashboard' => 2, 'products' => 2, 'categories' => 2,
            'colors_sizes' => 2, 'size_guides' => 2, 'orders' => 2,
            'shipping' => 2, 'coupons' => 2, 'customers' => 2,
            'admins' => 2, 'banners' => 2, 'pages' => 2, 'campaigns' => 2,
            'reports' => 2, 'settings' => 2,
        ],
        'manager' => [
            'dashboard' => 2, 'products' => 2, 'categories' => 2,
            'colors_sizes' => 2, 'size_guides' => 2, 'orders' => 2,
            'shipping' => 2, 'coupons' => 2, 'customers' => 2,
            'admins' => 1, 'banners' => 2, 'pages' => 2, 'campaigns' => 2,
            'reports' => 2, 'settings' => 1,
        ],
        'orders_staff' => [
            'dashboard' => 1, 'products' => 1, 'categories' => 0,
            'colors_sizes' => 0, 'size_guides' => 0, 'orders' => 2,
            'shipping' => 2, 'coupons' => 1, 'customers' => 2,
            'admins' => 0, 'banners' => 0, 'pages' => 0, 'campaigns' => 0,
            'reports' => 1, 'settings' => 0,
        ],
        'content_editor' => [
            'dashboard' => 1, 'products' => 2, 'categories' => 2,
            'colors_sizes' => 2, 'size_guides' => 1, 'orders' => 0,
            'shipping' => 0, 'coupons' => 0, 'customers' => 0,
            'admins' => 0, 'banners' => 2, 'pages' => 2, 'campaigns' => 2,
            'reports' => 0, 'settings' => 0,
        ],
        'viewer' => [
            'dashboard' => 1, 'products' => 1, 'categories' => 1,
            'colors_sizes' => 1, 'size_guides' => 1, 'orders' => 1,
            'shipping' => 1, 'coupons' => 1, 'customers' => 1,
            'admins' => 0, 'banners' => 1, 'pages' => 1, 'campaigns' => 1,
            'reports' => 1, 'settings' => 0,
        ],
    ];

    /**
     * The human labels of the modules the back office is split into.
     *
     * @var array<string, string>
     */
    public const MODULE_LABELS = [
        'dashboard' => 'Dashboard',
        'products' => 'Products',
        'categories' => 'Categories',
        'colors_sizes' => 'Colors and Sizes',
        'size_guides' => 'Size Guides',
        'orders' => 'Orders',
        'shipping' => 'Shipping',
        'coupons' => 'Coupons',
        'customers' => 'Customers',
        'admins' => 'Admins',
        'banners' => 'Banners',
        'pages' => 'Pages',
        'campaigns' => 'Campaigns',
        'reports' => 'Reports',
        'settings' => 'Settings',
    ];

    /**
     * The modules the back office is split into, in sidebar order.
     *
     * @return array<int, string>
     */
    public static function modules(): array
    {
        return array_keys(self::MATRIX['owner']);
    }

    /**
     * The human label of a module.
     */
    public static function moduleLabel(string $module): string
    {
        return self::MODULE_LABELS[$module] ?? ucfirst(str_replace('_', ' ', $module));
    }

    /**
     * The human label shown next to a role in the admin interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::OrdersStaff => 'Orders staff',
            self::ContentEditor => 'Content editor',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * The line under a role's name wherever the role is picked.
     *
     * It spells out what the role actually reaches, so the admin is
     * choosing against the real rules rather than a marketing label.
     */
    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access to every module. Always at least one Owner is required.',
            self::Manager => 'Can manage most modules except Admins and Settings.',
            self::OrdersStaff => 'Manages orders, shipping and customer support.',
            self::ContentEditor => 'Edits products, categories, banners, pages and campaigns.',
            self::Viewer => 'Read-only access to most modules.',
        };
    }

    /**
     * This role's access level for a module.
     *
     * An unknown module resolves to 0 (no access) rather than to a
     * default grant: a typo in a route's permission argument must fail
     * closed, not open.
     */
    public function levelFor(string $module): int
    {
        return self::MATRIX[$this->value][$module] ?? 0;
    }

    /**
     * Whether the role may open the module's screens.
     */
    public function canView(string $module): bool
    {
        return $this->levelFor($module) >= 1;
    }

    /**
     * Whether the role may change the module's rows.
     */
    public function canManage(string $module): bool
    {
        return $this->levelFor($module) >= 2;
    }

    /**
     * The whole map for this role, for the interface to draw.
     *
     * @return array<string, int>
     */
    public function permissions(): array
    {
        return self::MATRIX[$this->value];
    }

    /**
     * The module keys this role can reach at all, with their level.
     *
     * @return array<string, int>
     */
    public function granted(): array
    {
        return array_filter(
            $this->permissions(),
            fn (int $level): bool => $level > 0,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The role stored on a row, with an unknown value treated as the
     * least-privileged role.
     *
     * A row can only carry one of the five values — the column is filled
     * by this application and validated on write — but the fallback
     * matters if a row ever arrives from an import: an unrecognised role
     * must not inherit the owner's powers.
     */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Viewer;
    }
}
