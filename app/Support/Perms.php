<?php

namespace App\Support;

/** Central list of permissions (module.action) used by the seeder, role UI and menus. */
class Perms
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /** module key => label */
    public const MODULES = [
        'orders' => 'Orders',
        'production' => 'Production tracking (stage logs, rejects)',
        'deliveries' => 'Deliveries & challans',
        'customers' => 'Customers',
        'vendors' => 'Vendors',
        'vendor_payments' => 'Vendor payments (credit purchases)',
        'expenses' => 'Expenses',
        'assets' => 'Assets',
        'investors' => 'Investors',
        'investments' => 'Investments',
        'workers' => 'Workers / Staff',
        'work_entries' => 'Work Entries (piece-rate)',
        'salaries' => 'Salary Entries',
        'worker_payments' => 'Worker Payments & Advances',
        'invoices' => 'Invoices',
        'customer_payments' => 'Customer Payments',
        'inventory' => 'Inventory',
        'master' => 'Master Data (categories, rates, fields, currencies)',
        'users' => 'Users & Roles',
        'settings' => 'Settings & Notifications',
    ];

    public const REPORTS = [
        'orders' => 'Order-wise Cost & Profit',
        'expenses' => 'Expense Report',
        'labour' => 'Labour Payable / Paid',
        'worker_statement' => 'Worker Statement',
        'customer_ledger' => 'Customer Ledger',
        'cash' => 'Cash, Bank & Investments',
        'profit_loss' => 'Profit & Loss',
        'balance_sheet' => 'Balance Sheet',
        'assets' => 'Asset Register',
        'inventory' => 'Inventory Stock',
        'vendor_payables' => 'Vendor Payables (what we owe)',
        'production' => 'Production Progress by Order',
        'quality' => 'Rejects & Rework by Worker',
    ];

    public const EXTRA = [
        'data.all' => 'See all users\' records (without it: own records only)',
        'my.ledger' => 'View own worker ledger / balance sheet',
        'audit.view' => 'View audit log',
        'period.close' => 'Month close: close and re-open months',
        'period.override' => 'Month close: change records in a closed month by giving a reason',
        'backups.view' =>'Backups: view, create, download, upload and delete',
        'backups.restore' => 'Backups: RESTORE the whole system from a backup (dangerous — owner only)',
    ];

    public static function all(): array
    {
        $p = [];
        foreach (array_keys(self::MODULES) as $m) {
            foreach (self::ACTIONS as $a) {
                $p[] = "$m.$a";
            }
        }
        foreach (array_keys(self::REPORTS) as $r) {
            $p[] = "reports.$r";
        }
        return array_merge($p, array_keys(self::EXTRA));
    }
}
