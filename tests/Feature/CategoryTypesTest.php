<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** One expense category can be used under several expense types; a name never exists twice. */
class CategoryTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
    }

    public function test_one_category_can_carry_several_types_and_shows_once_in_filters(): void
    {
        $this->post('/categories', ['name' => 'Stones', 'types' => ['labour', 'order']])->assertSessionHasNoErrors();

        $c = ExpenseCategory::where('name', 'Stones')->first();
        $this->assertSame(['order', 'labour'], $c->types);          // stored in the fixed order
        $this->assertSame('order', $c->type);                         // legacy column mirrors the first
        $this->assertSame(1, ExpenseCategory::where('name', 'Stones')->count());

        $this->get('/categories')->assertOk()->assertSee('Stones')->assertSee('Order, Labour');
        $this->get('/categories?type=labour')->assertOk()->assertSee('Stones');
        $this->get('/categories?type=unit')->assertOk()->assertDontSee('Stones');
        $this->get('/expenses')->assertOk();   // the Category filter lists each name once
        $this->assertSame(1, substr_count($this->get('/expenses')->getContent(), '>Stones</option>'));
    }

    public function test_expense_form_offers_the_category_under_each_of_its_types_only(): void
    {
        $this->post('/categories', ['name' => 'Stones', 'types' => ['order', 'labour'], 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post('/categories', ['name' => 'Gold foil', 'types' => ['order'], 'is_active' => 1])->assertSessionHasNoErrors();

        $r = $this->get('/expenses/create')->assertOk();
        $cats = collect($r->viewData('extra')['categories']);
        $this->assertSame(['order', 'labour'], $cats->firstWhere('name', 'Stones')['types']);
        $this->assertSame(['order'], $cats->firstWhere('name', 'Gold foil')['types']);
        $r->assertSee('c.types.includes(type)', false);
    }

    public function test_a_name_cannot_be_created_twice_even_with_another_type_or_case(): void
    {
        $this->post('/categories', ['name' => 'Stones', 'types' => ['order']])->assertSessionHasNoErrors();
        $this->post('/categories', ['name' => 'stones', 'types' => ['labour']])->assertSessionHasErrors('name');
        $this->post('/categories', ['name' => 'Nothing ticked', 'types' => []])->assertSessionHasErrors('types');
        $this->post('/categories', ['name' => 'Bad', 'types' => ['nonsense']])->assertSessionHasErrors('types.0');
        $this->assertSame(1, ExpenseCategory::whereRaw('LOWER(name) = ?', ['stones'])->count());
    }

    public function test_a_type_cannot_be_removed_while_expenses_of_that_type_use_the_category(): void
    {
        $c = ExpenseCategory::create(['name' => 'Stones', 'type' => 'order', 'types' => ['order', 'labour']]);
        Expense::create(['type' => 'labour', 'category_id' => $c->id, 'date' => '2026-11-05', 'amount' => 50, 'base_amount' => 50, 'payment_mode' => 'cash']);

        $this->put("/categories/{$c->id}", ['name' => 'Stones', 'types' => ['order'], 'is_active' => 1])->assertSessionHasErrors('types');
        $this->assertSame(['order', 'labour'], $c->fresh()->types);

        $this->put("/categories/{$c->id}", ['name' => 'Stones', 'types' => ['order', 'labour', 'other'], 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['order', 'labour', 'other'], $c->fresh()->types);
        $this->get("/categories/{$c->id}/edit")->assertOk()->assertSee('name="types[]"', false);
    }

    public function test_migration_merges_same_name_categories_and_moves_their_expenses(): void
    {
        $migration = require base_path('database/migrations/2026_11_09_000003_expense_category_types.php');
        $migration->down();                                    // back to the old one-type-per-row shape
        $this->assertFalse(Schema::hasColumn('expense_categories', 'types'));

        DB::table('expense_categories')->delete();
        $now = now();
        $ins = fn ($id, $name, $type, $active = 1) => DB::table('expense_categories')->insert(['id' => $id, 'name' => $name, 'type' => $type, 'is_active' => $active, 'created_at' => $now, 'updated_at' => $now]);
        $ins(5, 'Embellishment', 'order');
        $ins(25, 'Embellishment ', 'labour');                  // same name (trailing space) under another type
        $ins(26, 'Dyeing', 'order');
        $exp = fn ($cat, $type) => DB::table('expenses')->insertGetId(['uuid' => (string) \Str::uuid(), 'type' => $type, 'category_id' => $cat, 'date' => '2026-11-05', 'amount' => 10, 'exchange_rate' => 1, 'base_amount' => 10, 'payment_mode' => 'cash', 'title' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $e1 = $exp(5, 'order');
        $e2 = $exp(25, 'labour');

        $migration->up();

        $this->assertSame(0, ExpenseCategory::where('id', 25)->count());
        $m = ExpenseCategory::find(5);
        $this->assertSame(['order', 'labour'], $m->types);
        $this->assertSame(5, Expense::find($e1)->category_id);
        $this->assertSame(5, Expense::find($e2)->category_id);   // moved onto the kept category
        $this->assertSame(['order'], ExpenseCategory::find(26)->types);
        $this->assertSame(2, ExpenseCategory::count());
    }
}
