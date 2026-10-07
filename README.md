# Lumiere Premium

A mobile-first business management app for a ladies' garment **stitching unit**: brands send bulk orders, the unit stitches them, and the owner needs one place to run orders, production, money and labour — from a phone in the market or a desktop in the office, with or without internet.

Built with **Laravel 12 · PHP 8.3 · MySQL 8 · Blade + Tailwind 4 + Alpine 3**, installable as a **PWA**, available in **English and Urdu (RTL)**.

## Features

| Area | What it does |
|---|---|
| **Orders** | Client, collection / brand, fabric, quantity, rate, due date, multiple photos; per-order cost vs revenue and profit; filters |
| **Production & deliveries** | Piece counts per stage (cutting → stitching → finishing → quality check → packing → delivered), production board, late-order alerts, partial deliveries with PDF challan, rejects / rework per worker with optional pay deduction |
| **Expenses** | Order, unit, labour and other expenses; admin-defined custom fields; **receipt upload with local OCR** (Tesseract) that pre-fills amount, date and payee; buy **on credit** from vendors |
| **Vendors** | Credit bills, payments, per-vendor ledger and aging, due-bill alerts |
| **Labour** | Piece-rate freelancers and salaried staff, per-garment rate card, advances, partial payments with carry-forward balance, deductions and bonuses, worker ledger, **payslip and payment-voucher PDFs** shareable on WhatsApp |
| **Customers & invoices** | Invoices with PDF, partial payments, **customer advances** applied to invoices, **statements** by PDF / WhatsApp / email |
| **Capital** | Assets, investors, investments, cash and bank position, balance sheet, profit & loss |
| **Inventory** | Items, stock movements, low-stock alerts |
| **Reports** | Order cost & profit, expenses, labour, customer ledger, vendor payables, production, quality, cash, P&L, balance sheet — all with Excel / PDF export |
| **Dashboard** | Week / month / year / custom period with change vs the previous period, charts, alerts |
| **Offline** | Records saved without internet queue in the browser and sync automatically (idempotent, no duplicates) |
| **Access control** | Super Admin / Admin / User, per-module permissions, "own records only" scope, worker self-service ledger, audit log |
| **Safety** | Nightly backups (14 nightly + 6 monthly) with optional off-site copy, restore, **month-end close** with admin override and written reason, **TOTP two-factor** with recovery codes, Super-Admin-only **reset test data** |
| **Notifications** | In-app, email and WhatsApp, switched per event by the admin |
| **UI** | Responsive, dark mode, global search, in-app **Help** for every module |

## Requirements

- PHP **8.3** with `mbstring`, `xml`, `zip`, `gd`, `intl`, `bcmath`, `fileinfo`, `curl`, `pdo_mysql`
- MySQL **8** (the `mysql` and `mysqldump` command-line tools must be installed — backups use them)
- Composer 2 and Node 20+
- Tesseract OCR for receipt reading: `sudo apt install tesseract-ocr` (add `tesseract-ocr-urd` for Urdu receipts)
- A web server pointing at `public/` (nginx + php-fpm, or `php artisan serve` for a quick look). **HTTPS** is needed for PWA install on phones.

## Installation

```bash
git clone git@github.com:wajahatbashir/laravel-stitching-unit-app.git
cd laravel-stitching-unit-app

composer install
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# edit .env: DB_*, APP_URL, OWNER_EMAIL / OWNER_PASSWORD (first Super Admin), MAIL_*

mysql -e "CREATE DATABASE lumiere CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate --seed
php artisan storage:link
```

Sign in with `OWNER_EMAIL` / `OWNER_PASSWORD`, then **change the password** on *Profile → Security* (and turn on two-factor). If you didn't set them, the defaults are `owner@example.com` / `ChangeMe@123`.

> On Windows, run all `php` / `composer` / `artisan` commands inside **WSL** (this project is developed on Ubuntu 22.04, where the PHP 8.3 binary is `php8.3`).

### Scheduler (needed for backups and alerts)

Add one cron entry; it runs the nightly backup (02:00) and the due-order / vendor-bill alerts (08:00):

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Configuration (`.env`)

| Variable | Purpose |
|---|---|
| `OWNER_EMAIL`, `OWNER_PASSWORD` | First Super Admin, created only on a fresh install |
| `MAIL_*` | Needed for statement emails and email notifications (default `log` just writes to the log) |
| `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID` | Optional WhatsApp Cloud API; without them messages are only logged. Sharing a PDF from the phone's share sheet needs no API |
| `BACKUP_DIR` | Where backups are written (default `storage/app/backups`) |
| `BACKUP_OFFSITE_PATH` | Also copy every backup to this folder — an external drive, or a folder synced by Google Drive / OneDrive / Dropbox |
| `BACKUP_OFFSITE_SSH` (+ `_KEY`, `_PORT`) | Or copy to another server over SSH (`user@host:/dir`, key login) |

Run `php artisan config:clear` after changing `.env`.

## Tests

```bash
mysql -e "CREATE DATABASE lumiere_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan test
```

Tests use the separate `lumiere_test` database and never touch your real data. The backup, reset and off-site tests rebuild that database and write only to temporary folders.

## Documentation

- [docs/USER_GUIDE.md](docs/USER_GUIDE.md) — day-to-day use (also available inside the app under **Help**)
- [docs/ADMIN_GUIDE.md](docs/ADMIN_GUIDE.md) — roles, settings, backups, month close, two-factor, reset
- [docs/DEVELOPER.md](docs/DEVELOPER.md) — architecture, offline sync protocol, money model, deployment checklist
- [PROJECT_NOTES.md](PROJECT_NOTES.md) — decisions, changelog and known limits

## Security notes

- Never commit `.env`. The seeded password is a placeholder: change it immediately.
- Backups contain all business data — keep the backup folder and any off-site copy private.
- Uploaded receipts and photos live in `storage/app/public/uploads`; PDFs are generated on request and not stored.
- In production set `APP_ENV=production`, `APP_DEBUG=false` and serve over HTTPS.

## Known limits

- Urdu text inside PDFs is not shaped correctly (English PDFs and Excel exports are fine).
- Edits and deletes need an internet connection; only new records can be created offline.
- OCR accuracy depends on the screenshot quality; extracted values are always editable.

## License

GNU General Public License v3.0 — see [LICENSE](LICENSE).
