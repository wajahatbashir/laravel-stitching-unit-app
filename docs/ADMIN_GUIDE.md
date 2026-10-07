# Lumiere Premium — Admin Guide

## Roles
| Role | Default access |
|---|---|
| **Super Admin** | everything, always (cannot be restricted); only they can assign Super Admin |
| **Admin** | all modules except Users & Roles |
| **User** | view orders; add expenses and work entries; own ledger |

Create more roles (e.g. *Supervisor*, *Accountant*) in **Roles & permissions**.

## Giving a worker access
1. **Users → Add**: name, email (login), password, role.
2. **Roles & permissions → Edit** the role: tick *View / Add / Edit / Delete* per module, which reports they may open, and special permissions:
   - **See all users' records** (`data.all`) — *off* = they see **only records they created**.
   - **View own worker ledger** (`my.ledger`) — their personal balance sheet.
   - **View audit log**.
3. **Workers → Edit** the worker and choose the *Login account* → this links the person to the user so *My ledger* shows their own earnings/payments.

Typical “worker with balance sheet” role: `work_entries.view/create`, `expenses.create`, `my.ledger`, no `data.all`.

## Master data (Admin menu)
- **Garments & rate card** — Shirt, Pant, Dupatta, 2 Piece, 3 Piece, Kaftan… default stitching rate per piece. Add any garment. Per-worker override on the worker page. Changing a rate affects **new** entries only.
- **Expense categories** — per type (order/unit/labour/other); add/deactivate (categories in use can't be deleted).
- **Custom fields** — extra fields on **Unit expenses**: text, number, date, long text, dropdown (comma-separated options), **image upload**; optional *required*; ordering.
- **Currencies** — rate = how many PKR (base) one unit is worth. Exactly one base currency. Update rates when needed; each expense keeps the rate it was saved with.

## Settings & notifications
*Settings & notifications*: business name/phone/address (shown on invoices), order & invoice prefixes, OCR languages (`eng`, or `eng+urd`), and **Branding** — upload the login-page logo, the dashboard/sidebar logo and the favicon (also the phone app icon; use a square image ≥ 512×512). PNG/JPG/WebP, max 3 MB; tick *Remove and use default* to revert.
**Notification matrix:** per event × channel (In-app · Email · WhatsApp) tick what you want. Events: new order, order due soon, expense added, worker paid, invoice created, customer payment, low stock.
- Notifications go to Super Admins/Admins.
- **Email:** set `MAIL_*` in `.env`.
- **WhatsApp:** set `WHATSAPP_TOKEN` and `WHATSAPP_PHONE_ID` (WhatsApp Cloud API) in `.env` and a phone number (with country code) on the admin user; until then messages are only written to `storage/logs`.
- *Order due soon* needs the scheduler: `* * * * * cd /var/www/html/local.lumiere-app.com && php8.3 artisan schedule:run`.

## Audit log
Every create/update/delete is recorded (who, what, changed fields, IP) — *Audit log* (needs `audit.view`).

## Housekeeping
- Records referenced elsewhere can't be deleted (customer with orders, worker with entries…) — deactivate instead.
- Backups: `mysqldump lumiere` and copy `storage/app/public` (receipts, attachments).
- After changing code: `php8.3 artisan optimize:clear`; after changing CSS/JS: `npm run build`.

## Backups
**Admin → Backups**: a backup (database + uploaded files) runs every night at 02:00 (needs the cron entry for `schedule:run`). The last 14 nightly and 6 monthly copies are kept in `storage/app/backups`; nothing downloads automatically — click *Download* when you want a copy (keep one off the server regularly). *Restore* is Super Admin only (password + typed confirmation); a safety backup is taken first. Failure raises a notification.

## Month close
**Admin → Month close**: close a finished month to freeze its records. Anyone with the *override* right can still save a record in a closed month by entering a written reason (kept in the log). Re-open a month if needed.

## Two-factor sign-in
Each user can enable an authenticator app under **Profile → Security** (save the 8 recovery codes). **Settings → Require 2FA for admins** forces it. If someone is locked out: **Users → Edit → Reset two-factor**.

## Documents sharing
Documents are never public: every PDF needs a login. Sharing sends the file itself (phone share sheet); on a computer the PDF is saved and WhatsApp opens with a text message. Email needs real `MAIL_*` values in `.env`. Vendor/delivery/overdue alerts are events in **Settings → Notifications**.

## Off-site backup
Backups on the same server are lost if the server is. In `.env` set ONE of `BACKUP_OFFSITE_PATH=/mnt/d/LumiereBackups` (external drive, or a folder synced by Google Drive/OneDrive/Dropbox for Desktop) or `BACKUP_OFFSITE_SSH=user@host:/backups/lumiere` (key login; optional `BACKUP_OFFSITE_SSH_KEY`, `BACKUP_OFFSITE_SSH_PORT`), then run `php8.3 artisan config:clear`. Each new backup is copied there automatically; the Backups page shows the last result and has *Copy latest now*. A failed copy sends a notification.

## Reset test data (Super Admin only)
**Admin → Reset test data**: after testing, wipe all test records (orders, customers, money, labour, production, uploads, logs) to start production with a clean system. Users, roles, settings, logos, rate card, categories and currencies stay. A *Before data reset* backup is made first; type RESET and your password. Numbering starts again from 1. To undo, restore that backup on the Backups page.
