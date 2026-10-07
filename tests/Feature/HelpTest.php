<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_lists_only_modules_the_user_can_open(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'owner@example.com')->first();
        $this->actingAs($owner)->get('/help')->assertOk()->assertSee('Month close')->assertSee('Backups')->assertSee('Vendor payments')->assertSee('id="orders"', false);

        $u = User::create(['name' => 'Worker', 'email' => 'w@x.test', 'password' => 'secret123']);
        $u->assignRole('User');
        $this->actingAs($u)->get('/help')->assertOk()->assertSee('Orders')->assertDontSee('Backups')->assertDontSee('Month close');
    }

    public function test_page_titles_link_to_their_help_section_and_every_section_is_valid(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'owner@example.com')->first());
        $this->get('/orders')->assertOk()->assertSee('/help#orders', false);
        $this->get('/worker-adjustments')->assertOk()->assertSee('/help#worker-adjustments', false);
        $this->get('/help')->assertOk()->assertDontSee('/help#help', false);
        foreach (config('help') as $k => $s) {
            $this->assertNotEmpty($s['purpose'], $k);
            $this->assertTrue(Route::has($s['route']), "route for $k");
        }
    }
}
