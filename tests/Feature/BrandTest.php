<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($this->owner);
        $this->c = Customer::create(['name' => 'Rose Garden']);
    }

    private function order(array $x = [])
    {
        return $this->postJson('/orders', $x + ['order_no' => 'T-'.uniqid(), 'date' => '2026-11-01', 'customer_id' => $this->c->id, 'qty' => 50, 'status' => 'pending']);
    }

    public function test_brand_list_is_seeded_and_the_order_form_has_separate_brand_and_collection_fields(): void
    {
        $this->assertSame(['Ayzel', 'Elaf', 'Maria.B.', 'Noore', 'Parishay'], Brand::orderBy('name')->pluck('name')->all());

        $this->get('/orders/create')->assertOk()
            ->assertSee('name="brand_id"', false)->assertSee('Maria.B.')->assertSee('Parishay')->assertSee('Elaf')
            ->assertSee('name="collection_name"', false)->assertSee('Collection')
            ->assertDontSee('Collection / Brand');
    }

    public function test_order_saves_brand_from_the_list_and_collection_typed_by_hand(): void
    {
        $maria = Brand::where('name', 'Maria.B.')->first();
        $this->order(['brand_id' => $maria->id, 'collection_name' => 'Eid Luxe'])->assertOk();
        $o = Order::first();
        $this->assertSame($maria->id, $o->brand_id);
        $this->assertSame('Eid Luxe', $o->collection_name);
        $this->assertSame('Maria.B. · Eid Luxe', $o->brand_collection);

        $this->order(['brand_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('brand_id');   // only brands from the list
        $this->order()->assertOk();                                                                       // brand is optional
        $this->assertNull(Order::orderByDesc('id')->first()->brand_id);

        $this->get("/orders/{$o->id}")->assertOk()->assertSee('Maria.B. · Eid Luxe');
        $this->get("/orders/{$o->id}/edit")->assertOk()->assertSee('value="'.$maria->id.'" selected', false)->assertSee('Eid Luxe');
        $this->get("/customers/{$this->c->id}")->assertOk()->assertSee('Maria.B. · Eid Luxe');
    }

    public function test_orders_list_has_brand_and_collection_columns_filter_search_and_reports(): void
    {
        $noore = Brand::where('name', 'Noore')->first();
        $maria = Brand::where('name', 'Maria.B.')->first();
        $this->order(['brand_id' => $maria->id, 'collection_name' => 'Eid Luxe'])->assertOk();
        $this->order(['brand_id' => $noore->id, 'collection_name' => 'Summer Lawn'])->assertOk();

        $this->get('/orders')->assertOk()->assertSee('Brand')->assertSee('Collection')->assertSee('Eid Luxe')->assertSee('Summer Lawn');
        $this->get('/orders?brand_id='.$noore->id)->assertOk()->assertSee('Summer Lawn')->assertDontSee('Eid Luxe');
        $this->get('/orders?q=Noore')->assertOk()->assertSee('Summer Lawn')->assertDontSee('Eid Luxe');
        $this->get('/search?q=Noore')->assertOk()->assertSee('Noore');

        $this->get('/reports/orders?brand_id='.$maria->id)->assertOk()->assertSee('Maria.B.')->assertSee('Eid Luxe')->assertDontSee('Summer Lawn');
        $this->get('/reports/orders?export=xlsx')->assertOk();
        $this->get('/reports/production')->assertOk()->assertSee('Maria.B. · Eid Luxe');
        $this->get('/production')->assertOk()->assertSee('Maria.B. · Eid Luxe');
    }

    public function test_inactive_brand_is_hidden_for_new_orders_but_kept_on_old_ones(): void
    {
        $elaf = Brand::where('name', 'Elaf')->first();
        $this->order(['brand_id' => $elaf->id, 'collection_name' => 'Formal'])->assertOk();
        $o = Order::first();
        $this->put("/brands/{$elaf->id}", ['name' => 'Elaf', 'is_active' => 0])->assertRedirect();

        $this->get('/orders/create')->assertOk()->assertDontSee('>Elaf<', false);
        $this->get("/orders/{$o->id}/edit")->assertOk()->assertSee('>Elaf<', false);   // still selectable where it is already used
        $this->get("/orders/{$o->id}")->assertOk()->assertSee('Elaf · Formal');
    }

    public function test_brands_master_page_add_rename_and_delete_rules(): void
    {
        $this->get('/brands')->assertOk()->assertSee('Maria.B.')->assertSee('Brands');
        $this->get('/dashboard')->assertSee('/brands', false);

        $this->post('/brands', ['name' => 'Sana Safinaz', 'is_active' => 1])->assertRedirect();
        $this->assertTrue(Brand::where('name', 'Sana Safinaz')->exists());
        $this->post('/brands', ['name' => 'noore'])->assertSessionHasErrors('name');   // duplicate names are refused (case-insensitive)

        $used = Brand::where('name', 'Noore')->first();
        $this->order(['brand_id' => $used->id])->assertOk();
        $this->delete("/brands/{$used->id}")->assertSessionHasErrors('delete');
        $this->assertTrue(Brand::whereKey($used->id)->exists());
        $free = Brand::where('name', 'Sana Safinaz')->first();
        $this->delete("/brands/{$free->id}")->assertRedirect();
        $this->assertFalse(Brand::whereKey($free->id)->exists());
    }

    public function test_only_users_with_master_data_access_can_manage_brands(): void
    {
        $u = User::create(['name' => 'Worker', 'email' => 'w@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->actingAs($u)->get('/brands')->assertForbidden();
        $this->post('/brands', ['name' => 'Sneaky'])->assertForbidden();
        $this->assertFalse(Brand::where('name', 'Sneaky')->exists());
    }
}
