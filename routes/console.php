<?php

use App\Models\Order;
use App\Services\BackupService;
use App\Services\Notifier;
use App\Services\OffsiteBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Daily: tell admins about open orders due within 2 days (honours Settings → Notifications).
Artisan::command('lumiere:due-orders', function () {
    $orders = Order::withoutGlobalScopes()->with('customer')
        ->whereIn('status', ['pending', 'in_progress'])
        ->whereBetween('due_date', [now()->toDateString(), now()->addDays(2)->toDateString()])->get();

    foreach ($orders as $o) {
        Notifier::send('order_due', "Order {$o->order_no} is due ".$o->due_date->format('d M'), $o->customer->name.' — '.$o->qty.' pcs', route('orders.show', $o, false));
    }
    $this->info($orders->count().' due order(s) notified.');

    // orders that are already late: one daily reminder each, with how much is still undelivered
    $late = Order::withoutGlobalScopes()->with('customer')
        ->whereIn('status', ['pending', 'in_progress'])->whereNotNull('due_date')->where('due_date', '<', now()->startOfDay())->get();
    foreach ($late as $o) {
        $s = \App\Support\Production::summary($o);
        $days = (int) $o->due_date->diffInDays(now()->startOfDay());
        Notifier::send('order_overdue', "Order {$o->order_no} is {$days} day(s) late", $o->customer->name.' — delivered '.$s['delivered'].' of '.$o->qty.' pcs (packed '.$s['stages']['packing'].')', route('orders.show', $o, false));
    }
    $this->info($late->count().' late order(s) notified.');

    // vendor bills (credit purchases) that are overdue or due within 3 days
    $billsDue = 0;
    foreach (\App\Models\Vendor::where('is_active', true)->get() as $v) {
        foreach ($v->openBills() as $b) {
            if ($b['due']->lte(now()->addDays(3)->endOfDay())) {
                $when = $b['due']->lt(now()->startOfDay()) ? 'is '.(int) $b['due']->diffInDays(now()->startOfDay()).' day(s) overdue' : 'is due '.$b['due']->format('d M');
                Notifier::send('vendor_bill_due', "Vendor bill {$when}: {$v->name}", number_format($b['left'], 2).' — '.($b['expense']->title ?: 'purchase'), route('vendors.show', $v, false));
                $billsDue++;
            }
        }
    }
    $this->info("$billsDue vendor bill(s) due.");
})->purpose('Notify admins about orders due soon or already late');

Schedule::command('lumiere:due-orders')->dailyAt('08:00');

// Nightly backup (database + uploaded files) into storage/app/backups; on the 1st a monthly copy is kept.
// Keeps 14 nightly + 6 monthly; manual/uploaded backups are never auto-deleted. Failure → "backup_failed" notification.
Artisan::command('lumiere:backup {--type=nightly : nightly | manual}', function () {
    try {
        $type = in_array($this->option('type'), ['nightly', 'manual'], true) ? $this->option('type') : 'nightly';
        $m = BackupService::create($type, 'scheduler');
        $this->info("Backup created: {$m['name']} (".number_format($m['size'] / 1048576, 2).' MB)');
        $copies = [$m['name']];
        if ($type === 'nightly' && now()->day === 1) {
            $copies[] = BackupService::duplicateAs($m['name'], 'monthly')['name'];
            $this->info('Monthly copy: '.end($copies));
        }
        $this->info(BackupService::prune().' old backup(s) removed.');

        if (OffsiteBackup::mode()) {
            foreach ($copies as $c) {
                $r = OffsiteBackup::push($c);
                $this->info($r['ok'] ? "Off-site copy OK: $c" : "Off-site copy FAILED: {$r['error']}");
                if (! $r['ok']) {
                    Notifier::send('backup_failed', 'Off-site backup copy FAILED', $r['error'], route('backups.index', [], false));
                }
            }
        }
    } catch (\Throwable $e) {
        report($e);
        Notifier::send('backup_failed', 'Automatic backup FAILED', $e->getMessage(), route('backups.index', [], false));
        $this->error('Backup failed: '.$e->getMessage());

        return 1;
    }
})->purpose('Create a database + files backup');

Schedule::command('lumiere:backup --type=nightly')->dailyAt('02:00')->withoutOverlapping();
