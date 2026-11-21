<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Payment receipts read themselves (amount / date / via) on the worker profile and on the payment forms. */
class ReceiptReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
    }

    public function test_every_receipt_box_is_wired_to_the_reader(): void
    {
        $w = Worker::create(['name' => 'Asif', 'is_active' => true]);

        foreach (["/workers/{$w->id}", '/worker-payments/create', '/customer-payments/create'] as $url) {
            $this->get($url)->assertOk()->assertSee('data-ocr-url="/receipts/ocr"', false)->assertSee('data-ocr-reading', false)->assertSee('name="receipt"', false);
        }
        // the expense form keeps its own reader
        $this->get('/expenses/create')->assertOk()->assertSee('expenses/ocr', false);
    }

    public function test_endpoint_reads_an_image_returns_the_fields_and_keeps_nothing(): void
    {
        \Storage::fake('local');
        $r = $this->postJson('/receipts/ocr', ['receipt' => UploadedFile::fake()->image('slip.png', 600, 900)])->assertOk();
        $r->assertJsonStructure(['text', 'amount', 'date', 'payee']);
        $this->assertSame([], \Storage::disk('local')->allFiles('tmp'));   // the uploaded copy is deleted straight away
    }

    public function test_endpoint_rejects_non_images_and_users_who_cannot_record_payments(): void
    {
        $this->postJson('/receipts/ocr', ['receipt' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->postJson('/receipts/ocr', [])->assertStatus(422);

        $u = User::create(['name' => 'Nobody', 'email' => 'nobody@x.test', 'password' => 'secret123']);
        $u->assignRole(Role::firstOrCreate(['name' => 'Nobody', 'guard_name' => 'web']));
        $this->actingAs($u)->postJson('/receipts/ocr', ['receipt' => UploadedFile::fake()->image('slip.png')])->assertForbidden();
        auth()->logout();
        $this->postJson('/receipts/ocr', ['receipt' => UploadedFile::fake()->image('slip.png')])->assertUnauthorized();
    }
}
