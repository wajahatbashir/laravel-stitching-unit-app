# Lumiere Premium — Developer Guide

Laravel 12 · PHP 8.3 · MySQL 8 · Blade/Tailwind 4/Alpine · Vite. **Run everything in WSL with `php8.3`.**

## Layout
```
app/Http/Controllers/CrudController.php   generic list/form/save/delete/export engine
app/Http/Controllers/*Controller.php      one per module (declare fields(), columns(), filters(), hooks)
app/Models/Concerns/Tracked.php           created_by, uuid, audit log, optional own-rows scope
app/Models/*                              Eloquent models ($guarded = [])
app/Support/Perms.php                     master list of permissions
app/Support/Ledger.php                    worker ledger / carry-forward maths
app/Support/Finance.php                   cash/bank, receivables, P&L, balance sheet
app/Services/ReceiptOcr.php               Tesseract + amount/date/payee parser
app/Services/Notifier.php                 event → channels per admin settings
app/Exports/TableExport.php               Excel (maatwebsite) / PDF (dompdf) from head+rows
resources/views/crud/{index,form}.blade.php  shared UI; module-specific: expenses/, work_entries/, invoices/, workers/
resources/js/offline.js                   IndexedDB outbox + sync;  public/sw.js  service worker
database/migrations/…create_lumiere_tables.php   whole schema
database/seeders/DatabaseSeeder.php       idempotent seed
tests/                                    Feature/SmokeTest (every page, ledger, sync, scoping), Unit/ReceiptOcrTest
```

## Adding a module (pattern)
1. Migration + model (`use Tracked;` for created_by/audit; `public static bool $hasUuid = true;` if it can be created offline; `$ownScoped = true` to limit non-`data.all` users).
2. `class XController extends CrudController` — set `$model,$module,$route,$title,$singular`, implement `fields()` and `columns()` (`[label, 'key.or.closure', ['money'|'badge'|'label'|'url' => …]]`), optionally `filters()`, `prepare()`, `saved()`, `beforeDelete()`, `formData()`.
3. Add `'uri' => XController::class` to the `$resources` array in `routes/web.php`; add `module.view/create/edit/delete` to `Perms::MODULES`; re-run `php8.3 artisan db:seed`; add a menu entry in `layouts/app.blade.php`.
4. Set `$offline = true` to make the create form queue offline (needs `uuid`). Extra form UI: `resources/views/<route>/_extra.blade.php`; list buttons: `<route>/_actions.blade.php`; show page: add `show()` and a view.

## Offline sync protocol
- Forms with `data-offline` are intercepted (`offline.js`). Online → `fetch` POST (Accept: JSON). Network error/timeout/offline → stored in IndexedDB (`lumiere-outbox`).
- Each submit gets a **fresh uuid**; `CrudController::store` returns success without inserting if a row with that uuid exists → replays are idempotent.
- Replay: on `online`, page load and every 30 s: `GET /csrf` for a fresh token, POST each item; 2xx → delete, 419 → refresh token, 401/302 → stop (logged out), 422 → mark *failed* with message (shown on `/sync`), network error → stop and keep the rest.
- Service worker: network-first for pages (cached copy / `/offline` as fallback), cache-first for `/build`, `/icons`, `/storage`; `precache` message after login caches the quick-add forms. `clear` message on logout.
- Conflict rule: creates always accepted; edits/deletes are online only (server wins).

## Money model
- `expenses.base_amount = amount × exchange_rate` (rate snapshot of the chosen currency; base currency has `is_base`).
- Worker balance = Σ(work_entries.amount + salary_entries.amount+bonus−deduction) − Σ(worker_payments.amount). Positive = payable; negative = advance held. Period status in `Ledger::build`.
- Cash/bank (`Finance::cashBank`): in = investments + customer payments; out = withdrawals + worker payments + expenses (`payment_mode` cash/other → cash, bank → bank).

## OCR
`ReceiptOcr::read($path)` → `thiagoalessio/tesseract_ocr` (`psm 6`, languages from setting `ocr_languages`) → `parse()` regexes (amount: keyword first, else largest currency-prefixed number; date: ISO/dmy/d-Mon-y/Mon d,y; payee: To/Beneficiary/Account title lines). Add bank-specific patterns in `parse()` and cover them in `tests/Unit/ReceiptOcrTest.php`.
Endpoint: `POST /expenses/ocr` (image, ≤10 MB, deleted after reading). Save-time fallback in `ExpenseController::prepare()` when amount is missing.

## Security notes
CSRF on all forms (offline queue refreshes token); permission checked in every CRUD action (`authorizeAction`); own-row scoping via global scope; throttle on login; uploads validated (type/size) and stored on the `public` disk (`php8.3 artisan storage:link`) — move receipts to a private disk if the server is internet-facing.

## Tests
`php8.3 artisan test` uses MySQL DB `lumiere_test` (RefreshDatabase — never point it at `lumiere`).

## Deployment checklist
`APP_ENV=production APP_DEBUG=false`, real `APP_URL` with HTTPS (needed for PWA install), `composer install --no-dev -o`, `npm ci && npm run build`, `php8.3 artisan migrate --force && db:seed --force`, `storage:link`, `config:cache route:cache view:cache`, cron for `schedule:run`, `MAIL_*`, optional `WHATSAPP_*`, `apt install tesseract-ocr tesseract-ocr-urd`, nightly DB + `storage/app/public` backup.

## Safety, production, payables, documents (rounds 9–12)
- **Backups:** `App\Services\BackupService` (zip: `database.sql` via mysqldump, uploads, manifest); config `backup.dir` / `backup.files_dir` (tests use temp dirs). Schedule in `routes/console.php`.
- **Month close:** `PeriodLock` (cached; `flush()` in tests) + `LocksPeriod` trait (`$lockColumn`) on models; `CrudController::withPeriodOverride()` handles the admin override.
- **2FA:** `App\Services\TwoFactor` (pragmarx/google2fa), `EnsureTwoFactor` middleware, session-based challenge.
- **Production:** `App\Support\Production` (summary, cap), models `ProductionLog|Reject`, `WorkerAdjustment`, `Delivery`; deductions flow through `Worker::earned`.
- **Vendor payables:** credit expenses + `VendorPayment`; `Vendor::openBills()` FIFO; `Finance::vendorPayables()` / balance sheet.
- **Documents:** `DocumentController` (`documents.pdf`, login required — no public links); share UI in `documents/_share.blade.php` (Alpine `shareDoc`, Web Share API → save PDF + wa.me text).
- Tests: Backup, PeriodClose, TwoFactor, Production, VendorPayables, Documents (62 total).
