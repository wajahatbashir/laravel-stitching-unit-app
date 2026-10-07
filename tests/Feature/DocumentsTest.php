<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentController;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerPayment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Worker $w;
    private WorkerPayment $pay;
    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($this->owner);
        $this->w = Worker::create(['name' => 'Sana', 'phone' => '0300-1234567', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 30000]);
        $this->post('/salaries/generate', ['month' => '2026-10'])->assertRedirect();
        $this->pay = WorkerPayment::create(['worker_id' => $this->w->id, 'type' => 'payment', 'date' => '2026-10-05', 'amount' => 10000, 'mode' => 'cash']);
        $this->c = Customer::create(['name' => 'Brand A', 'phone' => '03211234567', 'email' => 'a@brand.test']);
        Invoice::create(['number' => 'T-1', 'customer_id' => $this->c->id, 'date' => '2026-09-01', 'subtotal' => 5000, 'total' => 5000]);
        CustomerPayment::create(['customer_id' => $this->c->id, 'date' => '2026-09-10', 'amount' => 2000, 'mode' => 'bank']);
    }

    private function isPdf($res): void
    {
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_pdfs_are_generated(): void
    {
        $this->isPdf($this->get("/documents/payslip/{$this->w->id}?date_from=2026-10-01"));
        $this->isPdf($this->get("/documents/voucher/{$this->pay->id}"));
        $this->isPdf($this->get("/documents/statement/{$this->c->id}"));
        $this->get('/documents/other/1')->assertNotFound();
    }

    public function test_pages_show_share_buttons_and_whatsapp_number_is_international(): void
    {
        $this->get("/workers/{$this->w->id}")->assertOk()->assertSee('Share / WhatsApp');
        $this->get("/worker-payments/{$this->pay->id}")->assertOk()->assertSee('PV-')->assertSee('Share / WhatsApp');
        $this->get("/customers/{$this->c->id}")->assertOk()->assertSee('Share / WhatsApp')->assertSee('a@brand.test');
        $this->get('/worker-payments')->assertOk();
        $this->assertSame('923001234567', DocumentController::waNumber('0300-1234567'));
        $this->assertSame('923211234567', DocumentController::waNumber('+92 321 1234567'));
        $this->assertSame('923211234567', DocumentController::waNumber('00923211234567'));
    }

    public function test_documents_are_never_reachable_without_login(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get("/documents/statement/{$this->c->id}")->assertRedirect('/login');
        $this->get("/doc/statement/{$this->c->id}")->assertNotFound();
        $this->get("/documents/statement/{$this->c->id}/link")->assertNotFound();
    }

    public function test_page_titles_with_ampersand_are_not_double_escaped(): void
    {
        $this->get('/worker-adjustments')->assertOk()->assertSee('Deductions &amp;', false)->assertDontSee('&amp;amp;', false);
    }

    public function test_workers_only_get_their_own_documents(): void
    {
        $u = User::create(['name' => 'Worker', 'email' => 'w@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $mine = Worker::create(['name' => 'Mine', 'type' => 'salaried', 'pay_cycle' => 'monthly', 'monthly_salary' => 100, 'user_id' => $u->id]);
        $this->actingAs($u);
        $this->isPdf($this->get("/documents/payslip/{$mine->id}"));
        $this->get("/documents/payslip/{$this->w->id}")->assertForbidden();
        $this->get("/documents/voucher/{$this->pay->id}")->assertForbidden();
        $this->get("/documents/statement/{$this->c->id}")->assertForbidden();
    }

    public function test_statement_can_be_emailed_with_the_pdf_attached(): void
    {
        config(['mail.default' => 'array']);
        $this->post("/customers/{$this->c->id}/statement/email", ['to' => 'a@brand.test'])->assertSessionHas('success');
        $msgs = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $msgs);
        $this->assertCount(1, $msgs[0]->getOriginalMessage()->getAttachments());
        $this->post("/customers/{$this->c->id}/statement/email", ['to' => 'nope'])->assertSessionHasErrors('to');
    }
}
