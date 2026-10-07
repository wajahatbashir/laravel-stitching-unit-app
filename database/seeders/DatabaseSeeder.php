<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\CustomField;
use App\Models\ExpenseCategory;
use App\Models\GarmentType;
use App\Models\NotificationSetting;
use App\Models\Setting;
use App\Models\User;
use App\Support\Perms;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public const EVENTS = [
        'order_created' => 'New order created',
        'order_due' => 'Order due date approaching',
        'expense_added' => 'Expense added',
        'worker_payment' => 'Payment made to worker',
        'invoice_created' => 'Invoice created',
        'customer_payment' => 'Payment received from customer',
        'low_stock' => 'Inventory item low on stock',
        'backup_failed' => 'Automatic backup failed',
        'order_overdue' => 'Order is past its due date',
        'vendor_bill_due' => 'Vendor bill due or overdue',
        'delivery_created' => 'Delivery challan created',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Perms::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }

        $super = Role::findOrCreate('Super Admin', 'web');
        $admin = Role::findOrCreate('Admin', 'web');
        $user = Role::findOrCreate('User', 'web');

        $admin->syncPermissions(array_filter(Perms::all(), fn ($p) => ! str_starts_with($p, 'users.') && $p !== 'backups.restore'));
        $user->syncPermissions([
            'orders.view', 'expenses.view', 'expenses.create', 'work_entries.view', 'work_entries.create', 'my.ledger',
            'production.view', 'production.create',
        ]);

        // First install only: create the owner login (set OWNER_EMAIL / OWNER_PASSWORD in .env, then change the password after signing in).
        // Never touches an existing install — re-seeding must not add a second Super Admin with a default password.
        if (! User::role('Super Admin')->exists()) {
            User::firstOrCreate(['email' => env('OWNER_EMAIL', 'owner@example.com')], [
                'name' => 'Owner',
                'password' => env('OWNER_PASSWORD', 'ChangeMe@123'),
            ])->syncRoles([$super]);
        }

        // Currencies
        foreach ([['PKR', 'Pakistani Rupee', 'Rs', 1, true], ['USD', 'US Dollar', '$', 280, false],
            ['AED', 'UAE Dirham', 'AED', 76, false], ['SAR', 'Saudi Riyal', 'SAR', 75, false]] as [$c, $n, $s, $r, $b]) {
            Currency::firstOrCreate(['code' => $c], ['name' => $n, 'symbol' => $s, 'rate' => $r, 'is_base' => $b]);
        }

        // Garment rate card (admin can edit)
        foreach (['Shirt' => 0, 'Pant' => 0, 'Dupatta' => 0, '2 Piece' => 0, '3 Piece' => 0, 'Kaftan' => 0] as $n => $r) {
            GarmentType::firstOrCreate(['name' => $n], ['default_rate' => $r]);
        }

        // Expense categories
        $cats = [
            'order' => ['Laces', 'Organza', 'Shamoz', 'Shameez (Lining)', 'Embellishment', 'Buttons & Zips', 'Packing', 'Thread'],
            'unit' => ['Machine Purchase', 'Machine Repair', 'Furniture', 'Tools & Equipment', 'Maintenance'],
            'labour' => ['Food', 'Tea & Refreshments', 'Transport', 'Medical'],
            'other' => ['Rent', 'Electricity Bill', 'Gas Bill', 'Water Bill', 'Internet / Phone', 'Miscellaneous'],
        ];
        foreach ($cats as $type => $names) {
            foreach ($names as $n) {
                ExpenseCategory::firstOrCreate(['type' => $type, 'name' => $n]);
            }
        }

        // Sample custom fields for unit expenses (admin can add/remove)
        foreach ([['Item photo', 'item_photo', 'image', 0], ['Warranty (months)', 'warranty_months', 'number', 1]] as [$l, $k, $t, $s]) {
            CustomField::firstOrCreate(['entity' => 'unit_expense', 'key' => $k], ['label' => $l, 'type' => $t, 'sort' => $s]);
        }

        // Settings
        foreach (['business_name' => 'Lumiere Premium', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-',
            'business_phone' => '', 'business_address' => '', 'ocr_languages' => 'eng', 'low_stock_alerts' => '1'] as $k => $v) {
            if (Setting::where('key', $k)->doesntExist()) {
                Setting::put($k, $v);
            }
        }

        // Notification matrix
        foreach (array_keys(self::EVENTS) as $e) {
            foreach (['database' => true, 'mail' => false, 'whatsapp' => false] as $ch => $on) {
                NotificationSetting::firstOrCreate(['event' => $e, 'channel' => $ch], ['enabled' => $on]);
            }
        }
    }
}
