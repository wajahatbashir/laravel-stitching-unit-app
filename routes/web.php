<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GarmentTypeController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\InvestorController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalaryEntryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WorkEntryController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerPaymentController;
use Illuminate\Support\Facades\Route;

Route::view('/offline', 'offline')->name('offline');


// PWA manifest is dynamic so the admin-uploaded favicon becomes the installed app icon
Route::get('/manifest.webmanifest', function () {
    $i192 = brand_url('favicon_192', '/icons/icon-192.png');
    $i512 = brand_url('favicon_512', '/icons/icon-512.png');

    return response()->json([
        'name' => biz('business_name', 'Lumiere Premium'),
        'short_name' => \Illuminate\Support\Str::limit(biz('business_name', 'Lumiere'), 12, ''),
        'description' => 'Business management — '.biz('business_name', 'Lumiere Premium'),
        'start_url' => '/dashboard',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        'background_color' => '#0a0a0a',
        'theme_color' => '#0a0a0a',
        'icons' => [
            ['src' => $i192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $i512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => biz('brand_favicon') ? $i512 : '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        'shortcuts' => [
            ['name' => 'New expense', 'url' => '/expenses/create'],
            ['name' => 'Work entry', 'url' => '/work-entries/create'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json']);
})->name('manifest');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorVerify'])->middleware('throttle:6,1')->name('two-factor.verify');
});

Route::middleware(['auth', \App\Http\Middleware\EnsureTwoFactor::class])->group(function () {
    // two-factor setup (Profile → Security)
    Route::post('/profile/two-factor/enable', [AuthController::class, 'twoFactorEnable'])->name('two-factor.enable');
    Route::post('/profile/two-factor/confirm', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('/profile/two-factor/cancel', [AuthController::class, 'twoFactorCancel'])->name('two-factor.cancel');
    Route::post('/profile/two-factor/disable', [AuthController::class, 'twoFactorDisable'])->middleware('throttle:10,1')->name('two-factor.disable');
    Route::post('/profile/two-factor/recovery-codes', [AuthController::class, 'twoFactorRecoveryCodes'])->name('two-factor.recovery');

    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [AuthController::class, 'updatePassword'])->name('profile.password');
    Route::put('/profile/preferences', [AuthController::class, 'updatePreferences'])->name('profile.preferences');
    Route::get('/locale/{loc}', [AuthController::class, 'locale'])->name('locale');
    Route::get('/csrf', [AuthController::class, 'csrf'])->name('csrf');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/search', \App\Http\Controllers\SearchController::class)->name('search');
    Route::get('/audit-log', [NotificationController::class, 'audit'])->name('audit');
    Route::get('/help', \App\Http\Controllers\HelpController::class)->name('help');
    Route::get('/my-ledger', [WorkerController::class, 'myLedger'])->name('my-ledger');
    Route::view('/sync', 'sync')->name('sync');

    // extra (non-resource) routes — declared before the resources they could clash with
    Route::post('/expenses/ocr', [ExpenseController::class, 'ocr'])->name('expenses.ocr');
    Route::post('/salaries/generate', [SalaryEntryController::class, 'generate'])->name('salaries.generate');
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('/documents/{kind}/{id}', [\App\Http\Controllers\DocumentController::class, 'download'])->name('documents.pdf');
    Route::post('/customers/{id}/statement/email', [\App\Http\Controllers\DocumentController::class, 'emailStatement'])->name('customers.statement-email');
    Route::get('/production', [\App\Http\Controllers\ProductionBoardController::class, 'index'])->name('production.board');
    Route::get('/deliveries/{id}/pdf', [\App\Http\Controllers\DeliveryController::class, 'pdf'])->name('deliveries.pdf');
    Route::post('/invoices/{id}/apply-advance', [InvoiceController::class, 'applyAdvance'])->name('invoices.apply-advance');
    Route::delete('/invoices/{id}/allocations/{allocation}', [InvoiceController::class, 'removeAllocation'])->name('invoices.allocations.destroy');

    // module resources: uri => controller
    $resources = [
        'orders' => OrderController::class,
        'customers' => CustomerController::class,
        'vendors' => VendorController::class,
        'expenses' => ExpenseController::class,
        'assets' => AssetController::class,
        'investors' => InvestorController::class,
        'investments' => InvestmentController::class,
        'workers' => WorkerController::class,
        'work-entries' => WorkEntryController::class,
        'salaries' => SalaryEntryController::class,
        'worker-payments' => WorkerPaymentController::class,
        'worker-adjustments' => \App\Http\Controllers\WorkerAdjustmentController::class,
        'vendor-payments' => \App\Http\Controllers\VendorPaymentController::class,
        'production-logs' => \App\Http\Controllers\ProductionLogController::class,
        'production-rejects' => \App\Http\Controllers\ProductionRejectController::class,
        'deliveries' => \App\Http\Controllers\DeliveryController::class,
        'invoices' => InvoiceController::class,
        'customer-payments' => CustomerPaymentController::class,
        'inventory-items' => InventoryItemController::class,
        'stock' => StockMovementController::class,
        'users' => UserController::class,
        'categories' => ExpenseCategoryController::class,
        'garments' => GarmentTypeController::class,
        'custom-fields' => CustomFieldController::class,
        'currencies' => CurrencyController::class,
    ];
    foreach ($resources as $uri => $controller) {
        $r = Route::resource($uri, $controller)->names($uri);
        if (! method_exists($controller, 'show')) {
            $r->except('show');
        }
    }

    Route::resource('roles', RoleController::class)->except('show');

    // month close
    Route::get('/month-close', [\App\Http\Controllers\PeriodCloseController::class, 'index'])->name('periods.index');
    Route::post('/month-close', [\App\Http\Controllers\PeriodCloseController::class, 'close'])->name('periods.close');
    Route::post('/month-close/{closure}/reopen', [\App\Http\Controllers\PeriodCloseController::class, 'reopen'])->name('periods.reopen');

    // reset test data (Super Admin only, checked in the controller)
    Route::get('/reset-data', [\App\Http\Controllers\DataResetController::class, 'index'])->name('reset.index');
    Route::post('/reset-data', [\App\Http\Controllers\DataResetController::class, 'run'])->name('reset.run');

    // backups (admin): create / download / upload / delete; restore is owner-only
    Route::controller(\App\Http\Controllers\BackupController::class)->prefix('backups')->name('backups.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/upload', 'upload')->name('upload');
        Route::post('/offsite', 'offsite')->name('offsite');
        Route::get('/{name}/download', 'download')->name('download');
        Route::delete('/{name}', 'destroy')->name('destroy');
        Route::get('/{name}/restore', 'restoreForm')->name('restore.form');
        Route::post('/{name}/restore', 'restore')->name('restore');
    });

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{key}', [ReportController::class, 'show'])->name('reports.show');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});
