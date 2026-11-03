<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkerPaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Worker $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        Storage::fake('public');
        $this->w = Worker::create(['name' => 'Ayesha', 'phone' => '0300-1234567', 'type' => 'freelancer', 'pay_cycle' => 'weekly']);
    }

    private function base(array $x = []): array
    {
        return $x + ['worker_id' => $this->w->id, 'type' => 'advance', 'date' => '2026-11-03', 'amount' => 3000, 'mode' => 'bank'];
    }

    public function test_form_has_the_same_receipt_box_as_the_expense_form(): void
    {
        $this->get('/worker-payments/create')->assertOk()
            ->assertSee('Payment receipt (bank app screenshot / photo)')->assertSee('name="receipt"', false)->assertSee('border-dashed', false);
        $this->assertSame(1, substr_count($this->get('/worker-payments/create')->getContent(), 'name="receipt"'));   // drawn once, not also in the grid
    }

    public function test_advance_is_saved_with_its_slip_and_it_shows_everywhere(): void
    {
        $this->post('/worker-payments', $this->base(['receipt' => UploadedFile::fake()->image('transfer.png', 600, 900)]))->assertSessionHasNoErrors();
        $p = WorkerPayment::first();
        $this->assertSame('advance', $p->type);
        $this->assertStringStartsWith('uploads/worker_payments/', $p->receipt_path);
        Storage::disk('public')->assertExists($p->receipt_path);
        $url = Storage::url($p->receipt_path);

        $this->get('/worker-payments')->assertOk()->assertSee('Slip')->assertSee('View')->assertSee($url, false);
        $this->get("/worker-payments/{$p->id}")->assertOk()->assertSee('Payment slip (evidence)')->assertSee($url, false);           // the "view" page
        $this->get("/worker-payments/{$p->id}/edit")->assertOk()->assertSee('Saved receipt')->assertSee('Remove receipt')->assertSee($url, false);
        $this->get("/workers/{$this->w->id}")->assertOk()->assertSee($url, false);                                                  // worker ledger links the slip
        $this->get('/worker-payments?export=xlsx')->assertOk();
    }

    public function test_receipt_is_optional_and_only_images_or_pdf_up_to_10mb(): void
    {
        $this->post('/worker-payments', $this->base())->assertSessionHasNoErrors();
        $this->assertNull(WorkerPayment::first()->receipt_path);
        $this->post('/worker-payments', $this->base(['receipt' => UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream')]))->assertSessionHasErrors('receipt');
        $this->post('/worker-payments', $this->base(['receipt' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')]))->assertSessionHasErrors('receipt');
        $this->post('/worker-payments', $this->base(['receipt' => UploadedFile::fake()->create('slip.pdf', 80, 'application/pdf')]))->assertSessionHasNoErrors();
        $p = WorkerPayment::orderByDesc('id')->first();
        $this->get("/worker-payments/{$p->id}")->assertOk()->assertSee('Open PDF');
    }

    public function test_replace_remove_and_delete_clean_up_the_slip_file(): void
    {
        $this->post('/worker-payments', $this->base(['receipt' => UploadedFile::fake()->image('a.jpg', 400, 300)]))->assertSessionHasNoErrors();
        $p = WorkerPayment::first();
        $a = $p->receipt_path;

        $this->put("/worker-payments/{$p->id}", $this->base())->assertSessionHasNoErrors();                 // untouched edit keeps it
        $this->assertSame($a, $p->fresh()->receipt_path);

        $this->put("/worker-payments/{$p->id}", $this->base(['receipt' => UploadedFile::fake()->image('b.jpg', 400, 300)]))->assertSessionHasNoErrors();
        $b = $p->fresh()->receipt_path;
        $this->assertNotSame($a, $b);
        Storage::disk('public')->assertMissing($a);
        Storage::disk('public')->assertExists($b);

        $this->put("/worker-payments/{$p->id}", $this->base(['remove_receipt' => 1]))->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->receipt_path);
        Storage::disk('public')->assertMissing($b);

        $this->put("/worker-payments/{$p->id}", $this->base(['receipt' => UploadedFile::fake()->image('c.jpg', 400, 300)]))->assertSessionHasNoErrors();
        $c = $p->fresh()->receipt_path;
        $this->delete("/worker-payments/{$p->id}")->assertRedirect();
        Storage::disk('public')->assertMissing($c);
    }

    public function test_quick_payment_form_on_the_worker_page_can_carry_the_slip(): void
    {
        $this->get("/workers/{$this->w->id}")->assertOk()->assertSee('enctype="multipart/form-data"', false)->assertSee('name="receipt"', false);
        $this->post('/worker-payments', $this->base(['type' => 'payment', 'back_to_worker' => $this->w->id, 'receipt' => UploadedFile::fake()->image('s.jpg', 300, 300)]))
            ->assertRedirect(route('workers.show', $this->w->id));
        Storage::disk('public')->assertExists(WorkerPayment::first()->receipt_path);
    }

    public function test_a_linked_worker_sees_the_slip_of_their_own_payment_in_my_ledger(): void
    {
        $u = User::create(['name' => 'Ayesha', 'email' => 'ay@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->w->update(['user_id' => $u->id]);
        $this->post('/worker-payments', $this->base(['receipt' => UploadedFile::fake()->image('t.jpg', 300, 300), 'worker_id' => $this->w->id]))->assertSessionHasNoErrors();
        $p = WorkerPayment::first();
        $p->update(['created_by' => $u->id]);   // visible to the own-records scope in this test

        $this->actingAs($u)->get('/my-ledger')->assertOk()->assertSee(Storage::url($p->receipt_path), false);
    }
}
