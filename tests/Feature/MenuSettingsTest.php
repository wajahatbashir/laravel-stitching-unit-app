<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Menu;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($this->owner);
    }

    private function save(array $shown, array $extra = [])
    {
        return $this->put('/settings', $extra + ['business_name' => 'Lumiere', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-', 'menu_submitted' => 1, 'menu' => $shown]);
    }

    /** Everything except the given route keys. */
    private function allBut(array $hide): array
    {
        return array_values(array_diff(Menu::hideableRoutes(), $hide));
    }

    private function link(string $route): string
    {
        return '<a href="'.route($route).'" class="nav-link';
    }

    public function test_everything_is_visible_by_default_and_the_settings_page_lists_every_item(): void
    {
        $this->assertSame([], Menu::hidden());
        $page = $this->get('/dashboard')->assertOk()->getContent();
        foreach (['orders.index', 'vendors.index', 'work-entries.index', 'cash-transfers.index', 'backups.index'] as $r) {
            $this->assertStringContainsString($this->link($r), $page, "$r should be in the menu");
        }

        $s = $this->get('/settings')->assertOk()->assertSee('Tick what you want in the side menu')->assertSee('menu_submitted', false)->assertSee('always shown');
        foreach (['Vendors', 'Work entries', 'Cash ⇄ Bank', 'Backups', 'Production board'] as $label) {
            $s->assertSee($label);
        }
    }

    public function test_unticked_items_disappear_from_the_menu_but_the_page_still_opens(): void
    {
        $this->save($this->allBut(['vendors.index', 'vendor-payments.index', 'work-entries.index', 'stock.index']))->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['vendors.index', 'vendor-payments.index', 'work-entries.index', 'stock.index'], Menu::hidden());

        $page = $this->get('/dashboard')->assertOk()->getContent();
        foreach (['vendors.index', 'vendor-payments.index', 'work-entries.index', 'stock.index'] as $r) {
            $this->assertStringNotContainsString($this->link($r), $page, "$r should be hidden");
        }
        foreach (['orders.index', 'expenses.index', 'workers.index', 'reports.index'] as $r) {
            $this->assertStringContainsString($this->link($r), $page, "$r should still show");
        }

        // hiding is cosmetic: permissions decide access, so the pages still open by link
        $this->get('/vendors')->assertOk();
        $this->get('/work-entries')->assertOk();

        // the settings page shows the current choice
        $s = $this->get('/settings')->getContent();
        $this->assertMatchesRegularExpression('/name="menu\[\]" value="vendors\.index"(?![^>]*checked)/', $s);
        $this->assertMatchesRegularExpression('/name="menu\[\]" value="orders\.index"[^>]*checked/', $s);
    }

    public function test_dashboard_and_settings_cannot_be_hidden_and_a_forged_request_does_not_change_that(): void
    {
        $this->save([])->assertSessionHasNoErrors();                  // untick everything
        $this->assertNotContains('dashboard', Menu::hidden());
        $this->assertNotContains('settings', Menu::hidden());
        $page = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString($this->link('dashboard'), $page);
        $this->assertStringContainsString($this->link('settings'), $page);       // the way back is always there
        $this->assertStringNotContainsString($this->link('orders.index'), $page);

        Setting::put('hidden_menu', json_encode(['dashboard', 'settings', 'orders.index', 'no.such.route']));   // even a hand-edited value
        $this->assertSame(['orders.index', 'no.such.route'], Menu::hidden());
        $this->get('/settings')->assertOk();
    }

    public function test_empty_groups_lose_their_heading_and_show_all_brings_everything_back(): void
    {
        $production = ['production.board', 'production-logs.index', 'production-rejects.index', 'deliveries.index'];
        $this->save($this->allBut($production));
        $page = $this->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('>Production</p>', $page);          // the whole group heading is gone
        $this->assertStringContainsString('>Labour</p>', $page);

        $this->save(Menu::hideableRoutes());                                     // "Show all"
        $this->assertSame([], Menu::hidden());
        $this->assertStringContainsString('>Production</p>', $this->get('/dashboard')->getContent());
    }

    public function test_quick_add_and_the_phone_bottom_bar_follow_the_menu(): void
    {
        $before = $this->get('/dashboard')->getContent();
        $this->assertStringContainsString(route('expenses.create'), $before);
        $this->assertStringContainsString('repeat(5, minmax(0, 1fr))', $before);         // Home, Orders, +, Expenses, Labour

        $this->save($this->allBut(['expenses.index', 'orders.index', 'cash-transfers.index']));
        $after = $this->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('href="'.route('expenses.create').'"', $after);   // quick-add entries of hidden modules go too
        $this->assertStringNotContainsString('href="'.route('orders.create').'"', $after);
        $this->assertStringNotContainsString('href="'.route('cash-transfers.create').'"', $after);
        $this->assertStringContainsString('href="'.route('work-entries.create').'"', $after);
        $this->assertStringContainsString('repeat(3, minmax(0, 1fr))', $after);          // Home, +, Labour — columns re-balanced
        $this->assertStringContainsString('<a href="'.route('dashboard').'" class="tab', $after);
    }

    public function test_only_people_who_can_edit_settings_can_change_the_menu(): void
    {
        $u = User::create(['name' => 'Worker', 'email' => 'w@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->actingAs($u)->put('/settings', ['business_name' => 'X', 'order_prefix' => 'O', 'invoice_prefix' => 'I', 'menu_submitted' => 1, 'menu' => []])->assertForbidden();
        $this->assertSame([], Menu::hidden());
    }

    public function test_saving_settings_without_the_menu_section_does_not_touch_the_menu(): void
    {
        $this->save($this->allBut(['vendors.index']));
        $this->put('/settings', ['business_name' => 'Renamed', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-'])->assertSessionHasNoErrors();   // e.g. an older form / script
        $this->assertSame(['vendors.index'], Menu::hidden());
        $this->assertSame('Renamed', Setting::get('business_name'));
    }

    public function test_the_choice_applies_to_everyone_but_never_grants_access(): void
    {
        $this->save($this->allBut(['customers.index']));
        $u = User::create(['name' => 'Staff', 'email' => 's@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $page = $this->actingAs($u)->get('/dashboard')->getContent();
        $this->assertStringNotContainsString($this->link('customers.index'), $page);          // hidden for them too
        $this->assertStringNotContainsString($this->link('backups.index'), $page);            // never shown without permission, whatever the setting
    }
}
