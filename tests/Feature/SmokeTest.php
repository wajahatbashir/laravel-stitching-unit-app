<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\GarmentType;
use App\Models\Order;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_every_list_and_create_page_renders(): void
    {
        $c = Customer::create(['name' => 'Brand A']);
        Order::create(['order_no' => 'ORD-1', 'date' => now(), 'customer_id' => $c->id, 'qty' => 10, 'amount' => 1000]);
        $this->actingAs($this->owner);

        $bad = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            if (! in_array('GET', $r->methods()) || str_contains($uri, '{') || in_array($uri, ['up', 'logout', 'login', 'storage/{path}', 'csrf', 'my-ledger'])) {
                continue;
            }
            if (str_starts_with($uri, 'reports/')) {
                continue;
            }
            $res = $this->get('/'.$uri);
            if ($res->getStatusCode() >= 400) {
                $bad[] = "$uri → ".$res->getStatusCode();
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_every_report_renders_and_exports(): void
    {
        $this->actingAs($this->owner);
        foreach (array_keys(\App\Support\Perms::REPORTS) as $k) {
            $this->get("/reports/$k")->assertOk();
            $this->get("/reports/$k?export=xlsx")->assertOk();
            $this->get("/reports/$k?export=pdf")->assertOk();
        }
    }

    public function test_worker_ledger_carries_partial_payment_and_advance(): void
    {
        $this->actingAs($this->owner);
        $g = GarmentType::where('name', 'Shirt')->first();
        $g->update(['default_rate' => 150]);
        $w = Worker::create(['name' => 'Ayesha', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);

        // piece-rate: 20 shirts × 150 = 3000 (rate snapshot from rate card)
        $this->post('/work-entries', ['worker_id' => $w->id, 'garment_type_id' => $g->id, 'date' => '2026-10-01', 'qty' => 20])->assertRedirect();
        $this->assertEquals(3000, $w->fresh()->earned());

        // partial payment 1000 → 2000 carries forward
        $this->post('/worker-payments', ['worker_id' => $w->id, 'type' => 'payment', 'date' => '2026-10-07', 'amount' => 1000, 'mode' => 'cash'])->assertRedirect();
        $this->assertEquals(2000, $w->fresh()->balance());

        // next week: 10 more shirts → balance 3500
        $this->post('/work-entries', ['worker_id' => $w->id, 'garment_type_id' => $g->id, 'date' => '2026-10-10', 'qty' => 10])->assertRedirect();
        $this->assertEquals(3500, $w->fresh()->balance());

        // advance 5000 → worker holds 1500 advance (negative balance)
        $this->post('/worker-payments', ['worker_id' => $w->id, 'type' => 'advance', 'date' => '2026-10-11', 'amount' => 5000, 'mode' => 'cash'])->assertRedirect();
        $this->assertEquals(-1500, $w->fresh()->balance());

        $this->get("/workers/{$w->id}")->assertOk()->assertSee('Advance to adjust');
        $this->get("/workers/{$w->id}?date_from=2026-10-08&date_to=2026-10-31")->assertOk();
    }

    public function test_offline_sync_is_idempotent(): void
    {
        $this->actingAs($this->owner);
        $c = Customer::create(['name' => 'Brand A']);
        $payload = ['uuid' => 'b1f0c7a0-0000-4000-8000-000000000001', 'order_no' => 'ORD-9', 'date' => '2026-10-01', 'customer_id' => $c->id, 'qty' => 5, 'status' => 'pending'];

        $this->postJson('/orders', $payload)->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/orders', $payload)->assertOk(); // replay
        $this->assertSame(1, Order::where('uuid', $payload['uuid'])->count());
    }

    public function test_expense_types_and_validation(): void
    {
        $this->actingAs($this->owner);
        $c = Customer::create(['name' => 'Brand A']);
        $o = Order::create(['order_no' => 'ORD-2', 'date' => now(), 'customer_id' => $c->id, 'qty' => 10, 'amount' => 5000]);
        $cat = \App\Models\ExpenseCategory::where('type', 'order')->first();

        // order expense must have an order
        $this->postJson('/expenses', ['type' => 'order', 'date' => '2026-10-02', 'amount' => 500, 'payment_mode' => 'cash'])->assertStatus(422);
        $this->postJson('/expenses', ['type' => 'order', 'order_id' => $o->id, 'category_id' => $cat->id, 'date' => '2026-10-02', 'amount' => 500, 'payment_mode' => 'cash'])->assertOk();

        // multi-currency: 10 USD @ 280 → 2800 base
        $usd = \App\Models\Currency::where('code', 'USD')->first();
        $this->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'amount' => 10, 'currency_id' => $usd->id, 'payment_mode' => 'bank'])->assertOk();
        $this->assertEquals(2800, Expense::where('currency_id', $usd->id)->first()->base_amount);

        // order cost roll-up
        $this->assertEquals(500, $o->fresh()->materialCost());
        $this->get("/orders/{$o->id}")->assertOk();
    }

    public function test_user_without_data_all_sees_only_own_rows_and_is_blocked_from_admin(): void
    {
        $user = User::create(['name' => 'Worker', 'email' => 'w@x.com', 'password' => 'password123']);
        $user->assignRole('User');

        $this->actingAs($this->owner)->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'amount' => 100, 'payment_mode' => 'cash'])->assertOk();
        $this->actingAs($user)->postJson('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'amount' => 50, 'payment_mode' => 'cash'])->assertOk();

        $this->assertSame(1, Expense::count()); // global scope: own rows only
        $this->actingAs($this->owner);
        $this->assertSame(2, Expense::count());

        $this->actingAs($user)->get('/users')->assertForbidden();
        $this->get('/settings')->assertForbidden();
        $this->get('/reports/profit_loss')->assertForbidden();
    }

    public function test_master_records_can_be_created_without_uuid_columns(): void
    {
        $this->actingAs($this->owner);
        $this->post('/customers', ['name' => 'Khaadi'])->assertRedirect('/customers');
        $this->post('/vendors', ['name' => 'Lace House'])->assertRedirect('/vendors');
        $this->post('/investors', ['name' => 'Partner'])->assertRedirect('/investors');
        $this->post('/garments', ['name' => 'Frock', 'default_rate' => 200])->assertRedirect('/garments');
        $this->post('/categories', ['type' => 'order', 'name' => 'Pearls'])->assertRedirect('/categories');
        $this->post('/custom-fields', ['label' => 'Machine colour', 'type' => 'text'])->assertRedirect('/custom-fields');
        $this->post('/workers', ['name' => 'Rubina', 'type' => 'freelancer', 'pay_cycle' => 'weekly'])->assertRedirect('/workers');
        $this->post('/inventory-items', ['name' => 'Lace', 'unit' => 'meter'])->assertRedirect('/inventory-items');
        $this->assertDatabaseHas('customers', ['name' => 'Khaadi']);
        $this->assertDatabaseHas('custom_fields', ['key' => 'machine_colour']);
    }

    public function test_admin_can_upload_branding_and_manifest_uses_it(): void
    {
        \Storage::fake('public');
        $this->actingAs($this->owner);
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('icons.0.src', '/icons/icon-192.png');

        $this->put('/settings', [
            'business_name' => 'Lumiere Premium', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-',
            'login_logo' => UploadedFile::fake()->image('login.png', 600, 200),
            'dashboard_logo' => UploadedFile::fake()->image('dash.png', 300, 100),
            'favicon' => UploadedFile::fake()->image('fav.png', 600, 600),
        ])->assertSessionHasNoErrors();

        $this->assertNotEmpty(biz('brand_login_logo'));
        $this->assertNotEmpty(biz('brand_favicon_512'));
        \Storage::disk('public')->assertExists(biz('brand_favicon_192'));
        $this->get('/dashboard')->assertOk()->assertSee(biz('brand_dashboard_logo'), false);
        $this->post('/logout');
        $this->get('/login')->assertOk()->assertSee(biz('brand_login_logo'), false);
        $this->actingAs($this->owner);
        $this->get('/manifest.webmanifest')->assertJsonPath('icons.0.src', \Storage::url(biz('brand_favicon_192')).'?v='.biz('brand_version'));

        // svg is rejected, reset restores defaults
        $this->put('/settings', ['business_name' => 'X', 'order_prefix' => 'O', 'invoice_prefix' => 'I',
            'login_logo' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')])->assertSessionHasErrors('login_logo');
        $this->put('/settings', ['business_name' => 'Lumiere Premium', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-', 'reset_favicon' => 1, 'reset_login_logo' => 1]);
        $this->assertEmpty(biz('brand_favicon'));
        $this->get('/manifest.webmanifest')->assertJsonPath('icons.0.src', '/icons/icon-192.png');
    }

    public function test_order_attachments_can_be_added_listed_and_removed(): void
    {
        \Storage::fake('public');
        $this->actingAs($this->owner);
        $c = Customer::create(['name' => 'Brand A']);
        $base = ['order_no' => 'ORD-77', 'date' => '2026-10-01', 'customer_id' => $c->id, 'qty' => 5, 'status' => 'pending'];

        // several images at once
        $this->post('/orders', $base + ['files' => [
            UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.webp'),
        ]])->assertSessionHasNoErrors();
        $o = Order::where('order_no', 'ORD-77')->first();
        $this->assertSame(3, $o->attachments()->count());
        foreach ($o->attachments as $a) {
            \Storage::disk('public')->assertExists($a->path);
        }

        // edit form lists them with remove controls
        $this->get("/orders/{$o->id}/edit")->assertOk()->assertSee($o->attachments->first()->name)->assertSee('remove_attachments[]', false);

        // remove one + add one in the same save; ids from another order are ignored
        $gone = $o->attachments->first();
        $other = Order::create(['order_no' => 'ORD-78', 'date' => '2026-10-01', 'customer_id' => $c->id, 'qty' => 1]);
        $foreign = $other->attachments()->create(['path' => 'x/y.png', 'name' => 'y.png', 'mime' => 'image/png']);
        $this->put("/orders/{$o->id}", $base + ['remove_attachments' => [$gone->id, $foreign->id], 'files' => [UploadedFile::fake()->image('d.jpg')]])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $o->attachments()->count()); // 3 - 1 + 1
        $this->assertNull($o->attachments()->find($gone->id));
        \Storage::disk('public')->assertMissing($gone->path);
        $this->assertNotNull($foreign->fresh(), 'attachments of other records must not be removable');

        // only images / pdf accepted
        $this->put("/orders/{$o->id}", $base + ['files' => [UploadedFile::fake()->create('evil.php', 10, 'text/x-php')]])->assertSessionHasErrors('files.0');

        // deleting the order removes its files
        $paths = $o->attachments->pluck('path');
        $o->delete();
        $o2 = Order::create(['order_no' => 'ORD-79', 'date' => '2026-10-01', 'customer_id' => $c->id, 'qty' => 1]);
        $a = $o2->attachments()->create(['path' => $paths->first(), 'name' => 'z', 'mime' => 'image/png']);
        $this->delete("/orders/{$o2->id}")->assertRedirect();
        $this->assertNull($a->fresh());
    }

    public function test_customer_advance_is_deducted_from_invoices_with_full_history(): void
    {
        $this->actingAs($this->owner);
        $c = Customer::create(['name' => 'Brand A']);
        $inv = fn (array $extra = [], float $amount = 6000) => $this->postJson('/invoices', [
            'number' => 'T-'.uniqid(), 'customer_id' => $c->id, 'date' => '2026-10-02', 'items' => [['description' => 'Stitching', 'qty' => 1, 'rate' => $amount]],
        ] + $extra);

        // advance = customer payment without an invoice
        $this->post('/customer-payments', ['customer_id' => $c->id, 'date' => '2026-10-01', 'amount' => 10000, 'mode' => 'bank'])->assertRedirect();
        $this->assertEquals(10000, $c->fresh()->advance());

        // unchecked box → invoice behaves as before (nothing deducted)
        $inv()->assertOk();
        $one = \App\Models\Invoice::first();
        $this->assertEquals(0, $one->paid());
        $this->assertEquals(6000, $one->balance());
        $this->assertEquals(10000, $c->fresh()->advance());

        $net = \App\Support\Finance::balanceSheet()['net'];

        // "Apply advance" on an existing invoice
        $this->post("/invoices/{$one->id}/apply-advance", ['amount' => 4000])->assertSessionHasNoErrors();
        $this->assertEquals(4000, $one->paid());
        $this->assertEquals(2000, $one->balance());
        $this->assertSame('partial', $one->status());
        $this->assertEquals(6000, $c->fresh()->advance());
        $this->assertEquals(2000, $c->fresh()->receivable());
        $this->assertEquals($net, \App\Support\Finance::balanceSheet()['net'], 'applying an advance must not change net worth');

        // cannot take more than the customer has, nor more than the invoice needs
        $inv(['apply_advance' => 1, 'advance_amount' => 6500], 20000)->assertStatus(422)->assertJsonValidationErrors('advance_amount');
        $inv(['apply_advance' => 1, 'advance_amount' => 5500], 5000)->assertStatus(422); // more than the invoice needs
        $this->assertSame(1, \App\Models\Invoice::count());
        $inv(['apply_advance' => 1], 3000)->assertStatus(422); // ticked but no amount

        // second invoice uses the rest; one advance payment now feeds two invoices
        $inv(['apply_advance' => 1, 'advance_amount' => 6000], 8000)->assertOk();
        $this->assertEquals(0, $c->fresh()->advance());
        $this->assertSame(2, \App\Models\PaymentAllocation::count());

        // apply button on an existing invoice (customer pays another advance)
        $this->post('/customer-payments', ['customer_id' => $c->id, 'date' => '2026-10-05', 'amount' => 500, 'mode' => 'cash'])->assertRedirect();
        $this->post("/invoices/{$one->id}/apply-advance", ['amount' => 5000])->assertSessionHasErrors('amount');
        $this->post("/invoices/{$one->id}/apply-advance", ['amount' => 500])->assertSessionHasNoErrors();
        $this->assertEquals(4500, $one->fresh()->paid());
        $this->assertEquals(0, $c->fresh()->advance());

        // pages render with history; applied advances are protected from deletion/edits
        $this->get("/invoices/{$one->id}")->assertOk()->assertSee('Advance applied');
        $this->get("/customers/{$c->id}")->assertOk()->assertSee('unused');
        $big = \App\Models\CustomerPayment::whereNull('invoice_id')->where('amount', 10000)->first();
        $this->delete("/customer-payments/{$big->id}")->assertSessionHasErrors('delete');
        $this->put("/customer-payments/{$big->id}", ['customer_id' => $c->id, 'date' => '2026-10-01', 'amount' => 5000, 'mode' => 'bank'])->assertSessionHasErrors('amount');

        // remove an allocation → money returns to the advance balance
        $alloc = $one->allocations()->first();
        $this->delete("/invoices/{$one->id}/allocations/{$alloc->id}")->assertRedirect();
        $this->assertEquals($alloc->amount, $c->fresh()->advance());

        // overview pages still fine
        foreach (['/dashboard', '/customers', '/customer-payments', '/reports/balance_sheet', '/reports/customer_ledger?customer_id='.$c->id] as $u) {
            $this->get($u)->assertOk();
        }
    }

    public function test_expense_receipt_can_be_previewed_replaced_removed_and_is_cleaned_up(): void
    {
        \Storage::fake('public');
        $this->actingAs($this->owner);
        $base = ['type' => 'other', 'date' => '2026-10-02', 'amount' => 700, 'payment_mode' => 'bank'];

        $this->post('/expenses', $base + ['receipt' => UploadedFile::fake()->image('first.png')])->assertSessionHasNoErrors();
        $e = Expense::first();
        $first = $e->receipt_path;
        \Storage::disk('public')->assertExists($first);

        // detail page + edit form show the saved receipt
        $this->get("/expenses/{$e->id}")->assertOk()->assertSee($first)->assertSee('data-lightbox', false);
        $this->get("/expenses/{$e->id}/edit")->assertOk()->assertSee($first)->assertSee('remove_receipt', false);
        $this->get('/expenses')->assertOk()->assertSee('data-lightbox', false);

        // replacing deletes the old file
        $this->put("/expenses/{$e->id}", $base + ['receipt' => UploadedFile::fake()->image('second.png')])->assertSessionHasNoErrors();
        $second = $e->fresh()->receipt_path;
        $this->assertNotSame($first, $second);
        \Storage::disk('public')->assertMissing($first);
        \Storage::disk('public')->assertExists($second);

        // untouched edit keeps it; "remove" clears it and deletes the file
        $this->put("/expenses/{$e->id}", $base)->assertSessionHasNoErrors();
        $this->assertSame($second, $e->fresh()->receipt_path);
        $this->put("/expenses/{$e->id}", $base + ['remove_receipt' => 1])->assertSessionHasNoErrors();
        $this->assertNull($e->fresh()->receipt_path);
        \Storage::disk('public')->assertMissing($second);
        $this->get("/expenses/{$e->id}")->assertOk()->assertSee('No receipt uploaded');

        // deleting an expense deletes its receipt
        $this->put("/expenses/{$e->id}", $base + ['receipt' => UploadedFile::fake()->image('third.png')]);
        $third = $e->fresh()->receipt_path;
        $this->delete("/expenses/{$e->id}")->assertRedirect();
        \Storage::disk('public')->assertMissing($third);
    }

    public function test_header_search_respects_permissions_and_finds_records(): void
    {
        $c = Customer::create(['name' => 'Zara Fashions', 'phone' => '0300111222']);
        $o = Order::create(['order_no' => 'ORD-5001', 'date' => '2026-10-01', 'customer_id' => $c->id, 'qty' => 5, 'collection_name' => 'Eid Lawn']);

        $this->actingAs($this->owner);
        $this->getJson('/search?q=z')->assertOk()->assertExactJson(['groups' => []]); // < 2 letters
        $res = $this->getJson('/search?q=zara')->assertOk();
        $titles = collect($res->json('groups'))->pluck('title')->all();
        $this->assertContains('Customers', $titles);
        $this->assertContains('Orders', $titles);          // found through the customer name
        $this->getJson('/search?q=Eid%20Lawn')->assertOk()->assertJsonPath('groups.0.items.0.label', 'ORD-5001');
        $this->getJson('/search?q=50%25')->assertOk();      // LIKE wildcards are escaped, no error

        // a user without module permissions gets nothing from those modules
        $u = User::create(['name' => 'Limited', 'email' => 'l@x.com', 'password' => 'password123']);
        $u->assignRole('User'); // can view orders + expenses only
        $groups = collect($this->actingAs($u)->getJson('/search?q=zara')->json('groups'))->pluck('title')->all();
        $this->assertContains('Orders', $groups);
        $this->assertNotContains('Customers', $groups);
        auth()->logout();
        $this->getJson('/search?q=zara')->assertUnauthorized();
    }

    public function test_notification_feed_and_mark_all_read(): void
    {
        $this->actingAs($this->owner);
        \App\Services\Notifier::send('order_created', 'New order X', 'body text', '/orders');
        $feed = $this->getJson('/notifications/latest')->assertOk();
        $this->assertSame(1, $feed->json('unread'));
        $this->assertSame('New order X', $feed->json('items.0.title'));
        $this->postJson('/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->getJson('/notifications/latest')->json('unread'));
    }

    public function test_profile_tabs_avatar_password_and_preferences(): void
    {
        \Storage::fake('public');
        $this->actingAs($this->owner);
        $this->get('/profile')->assertOk()->assertSee('My activity')->assertSee('Security');
        $this->get('/dashboard')->assertOk()->assertSee('Good ', false)->assertSee('Revenue vs costs');

        // name/phone + photo (cropped to a square PNG), then remove it
        $this->put('/profile', ['name' => 'Sara K', 'phone' => '0300', 'avatar' => UploadedFile::fake()->image('me.jpg', 600, 400)])->assertSessionHasNoErrors();
        $u = $this->owner->fresh();
        $this->assertSame('Sara K', $u->name);
        $this->assertNotNull($u->avatar);
        \Storage::disk('public')->assertExists($u->avatar);
        [$w, $h] = getimagesizefromstring(\Storage::disk('public')->get($u->avatar));
        $this->assertSame([256, 256], [$w, $h]);
        $this->put('/profile', ['name' => 'Sara K', 'remove_avatar' => 1]);
        $this->assertNull($this->owner->fresh()->avatar);

        // avatar must be an image
        $this->put('/profile', ['name' => 'X', 'avatar' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasErrors('avatar');

        // password: wrong current / too short / mismatch rejected; correct change works
        $this->put('/profile/password', ['current_password' => 'wrong', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertSessionHasErrors('current_password');
        $this->put('/profile/password', ['current_password' => 'ChangeMe@123', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put('/profile/password', ['current_password' => 'ChangeMe@123', 'password' => 'NewPass123', 'password_confirmation' => 'other'])->assertSessionHasErrors('password');
        $this->put('/profile/password', ['current_password' => 'ChangeMe@123', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertSessionHasNoErrors();
        $this->assertTrue(\Hash::check('NewPass123', $this->owner->fresh()->password));

        // language preference
        $this->put('/profile/preferences', ['locale' => 'ur'])->assertSessionHasNoErrors();
        $this->assertSame('ur', $this->owner->fresh()->locale);
        $this->put('/profile/preferences', ['locale' => 'xx'])->assertSessionHasErrors('locale');

        // last-login is recorded at sign-in
        auth()->logout();
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'NewPass123'])->assertRedirect();
        $this->assertNotNull($this->owner->fresh()->last_login_at);
    }

    public function test_dashboard_period_week_month_year_custom(): void
    {
        \Carbon\Carbon::setTestNow('2026-10-07 12:00:00'); // a Wednesday
        $this->actingAs($this->owner);
        $c = Customer::create(['name' => 'Brand A']);
        $inv = fn ($date, $total) => \App\Models\Invoice::create(['number' => 'T-'.uniqid(), 'customer_id' => $c->id, 'date' => $date, 'subtotal' => $total, 'total' => $total]);
        $inv('2026-10-05', 1000); $inv('2026-09-20', 400); $inv('2025-12-01', 200);
        Expense::create(['type' => 'other', 'date' => '2026-10-06', 'amount' => 300, 'base_amount' => 300, 'payment_mode' => 'cash']);
        Expense::create(['type' => 'other', 'date' => '2026-09-25', 'amount' => 100, 'base_amount' => 100, 'payment_mode' => 'cash']);
        \App\Models\CustomerPayment::create(['customer_id' => $c->id, 'date' => '2026-10-06', 'amount' => 500, 'mode' => 'bank']);
        Order::create(['order_no' => 'ORD-9', 'date' => '2026-10-06', 'customer_id' => $c->id, 'qty' => 40]);

        $stats = fn ($key, $off = 0) => \App\Support\Finance::periodStats(...\App\Support\DashboardPeriod::range($key, $off));
        $this->assertEquals(['revenue' => 1000.0, 'costs' => 300.0, 'profit' => 700.0, 'received' => 500.0, 'orders' => 1, 'pieces' => 40], collect($stats('week'))->only(['revenue', 'costs', 'profit', 'received', 'orders', 'pieces'])->all());
        $this->assertEquals(1000, $stats('month')['revenue']);
        $this->assertEquals(400, $stats('month', -1)['revenue']);          // previous month
        $this->assertEquals(1400, $stats('year')['revenue']);
        $this->assertEquals(400, $stats('year')['costs']);
        $this->assertEquals(200, $stats('year', -1)['revenue']);           // previous year
        $this->assertSame(150, \App\Support\Finance::delta(1000, 400));     // +150 % vs previous month
        $this->assertNull(\App\Support\Finance::delta(10, 0));              // nothing to compare with

        // period resolution + edge cases
        $req = fn (array $q) => \App\Support\DashboardPeriod::resolve(\Illuminate\Http\Request::create('/dashboard', 'GET', $q));
        $this->assertSame('month', $req([])->key);
        $this->assertSame('month', $req(['period' => 'bogus'])->key);
        $this->assertSame('month', $req(['period' => 'custom', 'from' => 'nonsense', 'to' => 'x'])->key);
        $this->assertSame(0, $req(['period' => 'month', 'offset' => 5])->offset);         // no future periods
        $swap = $req(['period' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-15']);
        $this->assertSame(['2026-09-15', '2026-09-30'], [$swap->from->toDateString(), $swap->to->toDateString()]);
        $this->assertSame(['day', 'week', 'month', 'week', 'month'], [$req(['period' => 'week'])->bucket(), $req(['period' => 'month'])->bucket(),
            $req(['period' => 'year'])->bucket(), $req(['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-21'])->bucket(),
            $req(['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30'])->bucket()]);
        $this->assertSame('2026-10-07', $req(['period' => 'week'])->chartTo()->toDateString()); // charts stop at today
        [$pf, $pt] = $req(['period' => 'custom', 'from' => '2026-09-15', 'to' => '2026-09-30'])->previous();
        $this->assertSame(['2026-08-30', '2026-09-14'], [$pf->toDateString(), $pt->toDateString()]); // same length, right before

        // chart buckets add up to the headline number
        $year = $req(['period' => 'year']);
        $series = \App\Support\Finance::series($year->from, $year->chartTo(), $year->bucket());
        $this->assertCount(10, $series);                                    // Jan..Oct
        $this->assertEquals(1400, collect($series)->sum('revenue'));
        $this->assertCount(3, \App\Support\Finance::series(...[$req(['period' => 'week'])->from, $req(['period' => 'week'])->chartTo(), 'day'])); // Mon-Wed

        // the page renders for every option, shows the right label and numbers
        $this->get('/dashboard')->assertOk()->assertSee('October 2026')->assertSee('Rs 1,000.00')->assertSee('150%');
        $this->get('/dashboard?period=week')->assertOk()->assertSee('05 Oct – 11 Oct 2026')->assertSee('Rs 700.00');
        $this->get('/dashboard?period=year')->assertOk()->assertSee('2026')->assertSee('Rs 1,400.00');
        $this->get('/dashboard?period=month&offset=-1')->assertOk()->assertSee('September 2026')->assertSee('Rs 400.00');
        $this->get('/dashboard?period=custom&from=2026-09-15&to=2026-09-30')->assertOk()->assertSee('15 Sep – 30 Sep 2026')->assertSee('Rs 400.00');
        $this->get('/dashboard?period=custom&from=garbage&to=junk')->assertOk()->assertSee('October 2026');
        \Carbon\Carbon::setTestNow();
    }

    public function test_urdu_locale_switches_to_rtl(): void
    {
        $this->actingAs($this->owner)->get('/locale/ur')->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertSee('dir="rtl"', false)->assertSee('ڈیش بورڈ');
    }

    public function test_due_order_command_runs(): void
    {
        $this->artisan('lumiere:due-orders')->assertSuccessful();
    }

    public function test_linked_worker_sees_own_ledger_only(): void
    {
        $user = User::create(['name' => 'Sana', 'email' => 's@x.com', 'password' => 'password123']);
        $user->assignRole('User');
        $mine = Worker::create(['name' => 'Sana', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 30000, 'user_id' => $user->id]);
        $other = Worker::create(['name' => 'Other', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);

        $this->actingAs($user)->get('/my-ledger')->assertOk()->assertSee('Sana');
        $this->get("/workers/{$other->id}")->assertForbidden(); // no workers.view permission
        $this->get('/dashboard')->assertOk()->assertSee('My balance');
        $this->actingAs($this->owner)->get('/my-ledger')->assertOk()->assertSee('No worker profile is linked'); // friendly page, not a 404
        $this->get('/dashboard')->assertOk()->assertDontSee(route('my-ledger'), false); // menu link hidden without a worker profile
    }

    public function test_salary_generation_creates_one_entry_per_employee_per_month(): void
    {
        $this->actingAs($this->owner);
        $w = Worker::create(['name' => 'Emp', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 25000]);
        $this->post('/salaries/generate', ['month' => '2026-10'])->assertRedirect();
        $this->post('/salaries/generate', ['month' => '2026-10'])->assertRedirect(); // no duplicates
        $this->assertSame(1, $w->salaryEntries()->count());
        $this->assertEquals(25000, $w->fresh()->balance());
        $this->post('/worker-payments', ['worker_id' => $w->id, 'type' => 'advance', 'date' => '2026-10-05', 'amount' => 5000, 'mode' => 'cash'])->assertRedirect();
        $this->assertEquals(20000, $w->fresh()->balance());
    }

    public function test_receipt_upload_without_amount_is_read_by_ocr_or_rejected(): void
    {
        $this->actingAs($this->owner);
        \Storage::fake('public');
        $res = $this->post('/expenses', ['type' => 'other', 'date' => '2026-10-02', 'payment_mode' => 'bank',
            'receipt' => UploadedFile::fake()->image('r.png', 400, 300)], ['Accept' => 'application/json']);
        $res->assertStatus(422); // blank image: nothing to read → asks for amount
    }
}
