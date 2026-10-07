<?php

/*
 | In-app help (/help). Key = route-name prefix, so the "?" next to a page title can find its section.
 | 'perm' = permission needed to see the section (null = everyone). Texts go through __() when rendered.
 | group, title, purpose, how (steps), tips are plain English strings.
 */
return [
    'dashboard' => ['group' => 'Business', 'title' => 'Dashboard', 'route' => 'dashboard', 'perm' => null,
        'purpose' => 'One-screen health check of the business for the period you choose.',
        'how' => ['Pick This week, This month, This year or Custom at the top; use the arrows to step to earlier periods.',
            'Tiles show revenue, costs, profit, payments received and wages, each with the change versus the previous period.',
            'Money position (cash, bank, receivables, payables) is always as of today.',
            'Late orders, vendor bills due and low stock appear as alerts.'],
        'tips' => ['The charts show revenue vs costs and where the money went.']],

    'orders' => ['group' => 'Business', 'title' => 'Orders', 'route' => 'orders.index', 'perm' => 'orders.view',
        'purpose' => 'Bulk stitching jobs from your clients (brands): what was ordered, by when, and what it cost.',
        'how' => ['Add an order: client, collection / brand, fabric, quantity, rate, due date and photos.',
            'Open an order to see costs (materials + labour), production progress, deliveries and profit.',
            'Use Filters to find orders by client, collection, fabric, status or date.'],
        'tips' => ['Expenses and work entries linked to an order are what make its profit figure correct.']],

    'customers' => ['group' => 'Business', 'title' => 'Customers', 'route' => 'customers.index', 'perm' => 'customers.view',
        'purpose' => 'The brands you work for, with what they owe you and any advance you hold.',
        'how' => ['Add a customer with phone and email.', 'Open a customer to see orders, invoices and every payment.',
            'Pick a period and press PDF / Share / Email to send a statement.'],
        'tips' => ['A customer with orders or invoices can only be deactivated, not deleted.']],

    'invoices' => ['group' => 'Business', 'title' => 'Invoices', 'route' => 'invoices.index', 'perm' => 'invoices.view',
        'purpose' => 'Bills sent to customers, with a PDF and a record of what is paid.',
        'how' => ['Create an invoice from a customer (and optionally an order); add line items, discount and tax.',
            'Tick Deduct customer advance if the customer paid before the invoice.', 'Record payments from the invoice page; partial payments are fine.'],
        'tips' => ['Status moves Unpaid → Partial → Paid automatically.']],

    'customer-payments' => ['group' => 'Business', 'title' => 'Customer payments', 'route' => 'customer-payments.index', 'perm' => 'customer_payments.view',
        'purpose' => 'Money received from customers, either against an invoice or as an advance.',
        'how' => ['Add a payment: customer, amount, cash or bank. Choose an invoice, or leave it empty to keep it as an advance.',
            'Apply an advance to invoices later from the invoice page.'],
        'tips' => ['Unused advances are shown as money you hold for the customer, and count as a liability on the balance sheet.']],

    'production' => ['group' => 'Production', 'title' => 'Production board', 'route' => 'production.board', 'perm' => 'production.view',
        'purpose' => 'Shows every open order by stage (cutting, stitching, finishing, quality check, packing, delivered) and which are late.',
        'how' => ['Read the board to see how many pieces sit in each stage.', 'Late orders are flagged with days late.'],
        'tips' => ['Counts come from Production log entries, not from work entries.']],

    'production-logs' => ['group' => 'Production', 'title' => 'Production log', 'route' => 'production-logs.index', 'perm' => 'production.view',
        'purpose' => 'Record how many pieces of an order passed each stage.',
        'how' => ['Add an entry: order, stage, pieces, date.', 'Each stage count cannot exceed the order quantity.'],
        'tips' => []],

    'production-rejects' => ['group' => 'Production', 'title' => 'Rejects & rework', 'route' => 'production-rejects.index', 'perm' => 'production.view',
        'purpose' => 'Track faulty pieces and who was responsible.',
        'how' => ['Add a reject: order, worker, pieces, reason, rework or scrap.', 'Tick deduct from pay to reduce that worker\'s earnings by a set amount.'],
        'tips' => ['Deductions appear on the worker\'s ledger and in Deductions & bonuses.']],

    'deliveries' => ['group' => 'Production', 'title' => 'Deliveries', 'route' => 'deliveries.index', 'perm' => 'deliveries.view',
        'purpose' => 'Partial or full deliveries to the brand, each with a printable challan.',
        'how' => ['Add a delivery: order, pieces sent, vehicle, who received.', 'Open it and press Challan PDF.',
            'When all pieces are delivered the order becomes Delivered.'],
        'tips' => ['The app stops you delivering more than the order quantity (plus the allowed margin).']],

    'expenses' => ['group' => 'Expenses', 'title' => 'Expenses', 'route' => 'expenses.index', 'perm' => 'expenses.view',
        'purpose' => 'Every rupee spent: order materials, unit purchases, labour extras and other costs.',
        'how' => ['Choose the type: Order, Unit, Labour or Other.', 'Upload a payment receipt and the amount, date and payee are read for you; always check them.',
            'Pick On credit to buy from a vendor now and pay later (vendor required).'],
        'tips' => ['Order expenses need an order so profit per order is right.']],

    'vendors' => ['group' => 'Expenses', 'title' => 'Vendors', 'route' => 'vendors.index', 'perm' => 'vendors.view',
        'purpose' => 'Suppliers (lace, fabric, etc.) and what you owe each of them.',
        'how' => ['Add a vendor.', 'Open it for the ledger, unpaid bills and how late each one is.'],
        'tips' => ['The We owe column totals every unpaid credit bill.']],

    'vendor-payments' => ['group' => 'Expenses', 'title' => 'Vendor payments', 'route' => 'vendor-payments.index', 'perm' => 'vendor_payments.view',
        'purpose' => 'Payments you make against credit purchases.',
        'how' => ['Add a payment: vendor, amount, cash or bank.', 'Payments clear the oldest unpaid bill first.'],
        'tips' => ['Paying more than owed shows as Paid ahead.']],

    'inventory-items' => ['group' => 'Expenses', 'title' => 'Inventory', 'route' => 'inventory-items.index', 'perm' => 'inventory.view',
        'purpose' => 'Materials in stock (laces, organza, lining, buttons) with a low-stock warning.',
        'how' => ['Add an item with unit and minimum level.', 'Record movements on the Stock movements page.'],
        'tips' => []],

    'stock' => ['group' => 'Expenses', 'title' => 'Stock movements', 'route' => 'stock.index', 'perm' => 'inventory.view',
        'purpose' => 'Stock coming in and going out to orders.',
        'how' => ['Add a movement: item, in or out, quantity, optional order.'],
        'tips' => ['Stock is not valued on the balance sheet; purchases are expensed.']],

    'workers' => ['group' => 'Labour', 'title' => 'Workers & Staff', 'route' => 'workers.index', 'perm' => 'workers.view',
        'purpose' => 'Freelancers paid per piece and salaried staff, each with a running balance.',
        'how' => ['Add a worker, choose type and payment cycle, and set piece rates per garment.',
            'Open a worker for the ledger: earned, paid, balance. Unpaid balance carries forward.',
            'Record a payment or advance from there; share a payslip as PDF or WhatsApp.',
            'Link a Login account so the worker can see My ledger.'],
        'tips' => ['A negative balance is an advance that will be adjusted against future earnings.']],

    'work-entries' => ['group' => 'Labour', 'title' => 'Work entries', 'route' => 'work-entries.index', 'perm' => 'work_entries.view',
        'purpose' => 'Piece-rate work: who stitched how many of which garment.',
        'how' => ['Add an entry: worker, garment, quantity, date and optionally the order.', 'The rate comes from the rate card and is saved with the entry.'],
        'tips' => ['Changing a rate later only affects new entries.']],

    'salaries' => ['group' => 'Labour', 'title' => 'Monthly salaries', 'route' => 'salaries.index', 'perm' => 'salaries.view',
        'purpose' => 'Monthly pay for salaried staff.',
        'how' => ['Press Generate for a month to create one entry per employee (no duplicates).', 'Adjust deductions per entry if needed.'],
        'tips' => []],

    'worker-payments' => ['group' => 'Labour', 'title' => 'Payments & advances', 'route' => 'worker-payments.index', 'perm' => 'worker_payments.view',
        'purpose' => 'Cash or bank payments and advances to workers.',
        'how' => ['Add a payment or advance; partial payments are fine.', 'Open one (eye icon) for the payment voucher: PDF or WhatsApp.'],
        'tips' => []],

    'worker-adjustments' => ['group' => 'Labour', 'title' => 'Deductions & bonuses', 'route' => 'worker-adjustments.index', 'perm' => 'worker_payments.view',
        'purpose' => 'One-off deductions or bonuses on a worker\'s balance.',
        'how' => ['Add an adjustment with a reason.', 'Deductions from rejects are created automatically.'],
        'tips' => []],

    'my-ledger' => ['group' => 'Labour', 'title' => 'My ledger', 'route' => 'my-ledger', 'perm' => 'my.ledger',
        'purpose' => 'Your own earnings, payments and balance.',
        'how' => ['Pick a period to see what you earned and were paid.', 'Download your payslip.'],
        'tips' => []],

    'assets' => ['group' => 'Capital', 'title' => 'Assets', 'route' => 'assets.index', 'perm' => 'assets.view',
        'purpose' => 'Machines, furniture and equipment you own.',
        'how' => ['Add an asset with cost, purchase date, serial number and photos.'],
        'tips' => ['Also record the purchase as a Unit expense so cash drops correctly.']],

    'investors' => ['group' => 'Capital', 'title' => 'Investors', 'route' => 'investors.index', 'perm' => 'investors.view',
        'purpose' => 'People who put money into the business.',
        'how' => ['Add an investor, then record their money on Investments.'],
        'tips' => []],

    'investments' => ['group' => 'Capital', 'title' => 'Investments', 'route' => 'investments.index', 'perm' => 'investments.view',
        'purpose' => 'Money in (investment) and money back (withdrawal), into cash or bank.',
        'how' => ['Add a record: investor, amount, cash or bank, date.'],
        'tips' => ['This drives the cash and bank balances.']],

    'reports' => ['group' => 'Insights', 'title' => 'Reports', 'route' => 'reports.index', 'perm' => null,
        'purpose' => 'Profit and loss, balance sheet, ledgers, payables and more, with Excel and PDF export.',
        'how' => ['Open a report, set the filters, then export if needed.', 'You only see reports you have been given.'],
        'tips' => ['Balance sheet net worth = assets − worker payable − customer advances − vendor payables.']],

    'users' => ['group' => 'Admin', 'title' => 'Users', 'route' => 'users.index', 'perm' => 'users.view',
        'purpose' => 'Logins for you and your team.',
        'how' => ['Add a user with email, password and role.', 'Reset a locked-out user\'s two-factor from Edit.'],
        'tips' => []],

    'roles' => ['group' => 'Admin', 'title' => 'Roles & permissions', 'route' => 'roles.index', 'perm' => 'users.view',
        'purpose' => 'Decide what each kind of user can open and change.',
        'how' => ['Edit a role and tick View, Add, Edit, Delete per module and which reports are allowed.',
            'Without See all users\' records, a user only sees what they created.'],
        'tips' => ['Super Admin always has everything.']],

    'categories' => ['group' => 'Admin', 'title' => 'Expense categories', 'route' => 'categories.index', 'perm' => 'master.view',
        'purpose' => 'The category lists used on expenses, per type.',
        'how' => ['Add or deactivate categories. One in use cannot be deleted.'], 'tips' => []],

    'garments' => ['group' => 'Admin', 'title' => 'Garments & rate card', 'route' => 'garments.index', 'perm' => 'master.view',
        'purpose' => 'Garment types and the default stitching rate per piece.',
        'how' => ['Add garments and set the default rate.', 'Override a rate for one worker on their profile.'], 'tips' => []],

    'custom-fields' => ['group' => 'Admin', 'title' => 'Custom fields', 'route' => 'custom-fields.index', 'perm' => 'master.view',
        'purpose' => 'Extra fields on unit expenses (text, number, date, dropdown, image).',
        'how' => ['Add a field, choose its type and whether it is required.'], 'tips' => []],

    'currencies' => ['group' => 'Admin', 'title' => 'Currencies', 'route' => 'currencies.index', 'perm' => 'master.view',
        'purpose' => 'Foreign currencies and their rate against the base currency (PKR).',
        'how' => ['Update rates when they change; each expense keeps the rate it was saved with.'], 'tips' => []],

    'settings' => ['group' => 'Admin', 'title' => 'Settings & notifications', 'route' => 'settings', 'perm' => 'settings.view',
        'purpose' => 'Business details, logos, security rules and which alerts go to whom.',
        'how' => ['Set business name, address, prefixes and logos.', 'Tick which events notify by in-app, email or WhatsApp.',
            'Turn on Require 2FA for admins if wanted.'], 'tips' => ['Email needs real mail settings on the server.']],

    'periods' => ['group' => 'Admin', 'title' => 'Month close', 'route' => 'periods.index', 'perm' => 'period.close',
        'purpose' => 'Freeze finished months so old figures cannot change by accident.',
        'how' => ['Close a month once it is reconciled.', 'To change a record in a closed month an admin must give a written reason, or re-open the month.'],
        'tips' => []],

    'backups' => ['group' => 'Admin', 'title' => 'Backups', 'route' => 'backups.index', 'perm' => 'backups.view',
        'purpose' => 'Safety copies of the database and uploaded files.',
        'how' => ['A backup runs every night; the last 14 nightly and 6 monthly are kept.', 'Download only when you click; keep a copy off the server.',
            'Restore is for the owner only and takes a safety backup first.'],
        'tips' => ['Backups are never downloaded automatically.']],

    'reset' => ['group' => 'Admin', 'title' => 'Reset test data', 'route' => 'reset.index', 'perm' => 'backups.restore',
        'purpose' => 'One-time clean start: removes all test records so real production data can be entered (Super Admin only).',
        'how' => ['Use it after testing, before real data entry.', 'A safety backup is taken first; type RESET and your password to confirm.',
            'Users, roles, settings, logos, rate card, categories and currencies are kept.'],
        'tips' => ['Changed your mind? Restore the Before data reset backup from the Backups page.']],

    'audit' => ['group' => 'Admin', 'title' => 'Audit log', 'route' => 'audit', 'perm' => 'audit.view',
        'purpose' => 'Who created, changed or deleted what, and when.',
        'how' => ['Browse or filter the list.'], 'tips' => []],

    'profile' => ['group' => 'Account', 'title' => 'My profile', 'route' => 'profile', 'perm' => null,
        'purpose' => 'Your photo, password, language, theme and two-factor sign-in.',
        'how' => ['Security tab: change password and turn on the authenticator app (save the recovery codes).', 'Preferences tab: language and light/dark mode.'],
        'tips' => []],

    'sync' => ['group' => 'Account', 'title' => 'Working offline', 'route' => 'sync', 'perm' => null,
        'purpose' => 'Add records with no internet; they upload by themselves later.',
        'how' => ['Save as usual; the record waits in the queue.', 'Open Sync to see pending or rejected items; retry or discard them.'],
        'tips' => ['Edits and deletes need an internet connection.']],
];
