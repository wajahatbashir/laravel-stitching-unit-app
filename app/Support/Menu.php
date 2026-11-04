<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The side / phone menu, defined once. Settings → Menu lets an admin hide items; hidden items are only removed from the
 * menus and quick-add lists (access is still decided by roles & permissions, and pages remain reachable by link).
 */
class Menu
{
    /** Always visible, so nobody can hide the way back to the Settings page. */
    public const LOCKED = ['dashboard', 'settings'];

    /** @return array<string,array<int,array{0:string,1:string,2:string,3:?string,4:string}>> group => [label, route, icon, permission, active pattern] */
    public static function groups(): array
    {
        return [
            __('Business') => [
                [__('Dashboard'), 'dashboard', 'home', null, 'dashboard'],
                [__('Orders'), 'orders.index', 'orders', 'orders.view', 'orders.*'],
                [__('Customers'), 'customers.index', 'users', 'customers.view', 'customers.*'],
                [__('Invoices'), 'invoices.index', 'doc', 'invoices.view', 'invoices.*'],
                [__('Customer payments'), 'customer-payments.index', 'cash', 'customer_payments.view', 'customer-payments.*'],
            ],
            __('Production') => [
                [__('Production board'), 'production.board', 'orders', 'production.view', 'production.*'],
                [__('Production log'), 'production-logs.index', 'doc', 'production.view', 'production-logs.*'],
                [__('Rejects & rework'), 'production-rejects.index', 'shield', 'production.view', 'production-rejects.*'],
                [__('Deliveries'), 'deliveries.index', 'cube', 'deliveries.view', 'deliveries.*'],
            ],
            __('Expenses') => [
                [__('Expenses'), 'expenses.index', 'cash', 'expenses.view', 'expenses.*'],
                [__('Vendors'), 'vendors.index', 'building', 'vendors.view', 'vendors.*'],
                [__('Vendor payments'), 'vendor-payments.index', 'cash', 'vendor_payments.view', 'vendor-payments.*'],
                [__('Inventory'), 'inventory-items.index', 'cube', 'inventory.view', 'inventory-items.*'],
                [__('Stock movements'), 'stock.index', 'cube', 'inventory.view', 'stock.*'],
            ],
            __('Labour') => [
                [__('Workers & Staff'), 'workers.index', 'users', 'workers.view', 'workers.*'],
                [__('Work entries'), 'work-entries.index', 'orders', 'work_entries.view', 'work-entries.*'],
                [__('Monthly salaries'), 'salaries.index', 'cash', 'salaries.view', 'salaries.*'],
                [__('Payments & advances'), 'worker-payments.index', 'cash', 'worker_payments.view', 'worker-payments.*'],
                [__('Deductions & bonuses'), 'worker-adjustments.index', 'cash', 'worker_payments.view', 'worker-adjustments.*'],
                [__('My ledger'), 'my-ledger', 'doc', 'my.ledger', 'my-ledger'],
            ],
            __('Capital') => [
                [__('Assets'), 'assets.index', 'building', 'assets.view', 'assets.*'],
                [__('Investors'), 'investors.index', 'users', 'investors.view', 'investors.*'],
                [__('Investments'), 'investments.index', 'cash', 'investments.view', 'investments.*'],
                [__('Cash ⇄ Bank'), 'cash-transfers.index', 'cash', 'transfers.view', 'cash-transfers.*'],
            ],
            __('Insights') => [
                [__('Reports'), 'reports.index', 'chart', null, 'reports.*'],
                [__('Help'), 'help', 'doc', null, 'help'],
            ],
            __('Admin') => [
                [__('Users'), 'users.index', 'users', 'users.view', 'users.*'],
                [__('Roles & permissions'), 'roles.index', 'shield', 'users.view', 'roles.*'],
                [__('Expense categories'), 'categories.index', 'cog', 'master.view', 'categories.*'],
                [__('Brands'), 'brands.index', 'cog', 'master.view', 'brands.*'],
                [__('Garments & rate card'), 'garments.index', 'cog', 'master.view', 'garments.*'],
                [__('Custom fields'), 'custom-fields.index', 'cog', 'master.view', 'custom-fields.*'],
                [__('Currencies'), 'currencies.index', 'cog', 'master.view', 'currencies.*'],
                [__('Settings & notifications'), 'settings', 'cog', 'settings.view', 'settings*'],
                [__('Month close'), 'periods.index', 'doc', 'period.close', 'periods.*'],
                [__('Backups'), 'backups.index', 'shield', 'backups.view', 'backups.*'],
                [__('Reset test data'), 'reset.index', 'shield', null, 'reset.*'],
                [__('Audit log'), 'audit', 'doc', 'audit.view', 'audit'],
            ],
        ];
    }

    /** Every route key that an admin may hide (all menu items except the locked ones). */
    public static function hideableRoutes(): array
    {
        return collect(self::groups())->flatten(1)->pluck(1)->reject(fn ($r) => in_array($r, self::LOCKED, true))->values()->all();
    }

    /** @return string[] route keys currently hidden (the locked items are never in it) */
    public static function hidden(): array
    {
        $v = json_decode((string) Setting::get('hidden_menu'), true);

        return is_array($v) ? array_values(array_diff(array_map('strval', $v), self::LOCKED)) : [];
    }

    public static function isHidden(string $route): bool
    {
        return in_array($route, self::hidden(), true);
    }

    /** Save from the checkbox list: everything NOT ticked becomes hidden. */
    public static function saveShown(array $shown): void
    {
        $hidden = array_values(array_diff(self::hideableRoutes(), array_map('strval', $shown)));
        Setting::put('hidden_menu', json_encode($hidden));
    }
}
