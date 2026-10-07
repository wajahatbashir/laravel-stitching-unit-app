<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'owner@example.com')->first();
    }

    /** Turn two-factor on for the owner through the real screens; returns [secret, recovery codes]. */
    private function enroll(): array
    {
        $this->actingAs($this->owner)->post('/profile/two-factor/enable')->assertRedirect();
        $secret = session('two_factor_setup');
        $this->assertNotEmpty($secret);
        $this->get('/profile?tab=security')->assertOk()->assertSee('<svg', false)->assertSee('Confirm and turn on');

        $this->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($this->owner->fresh()->hasTwoFactor());

        $this->post('/profile/two-factor/confirm', ['code' => TwoFactor::engine()->getCurrentOtp($secret)])->assertSessionHasNoErrors();
        $codes = session('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($this->owner->fresh()->hasTwoFactor());
        $this->get('/profile?tab=security')->assertSee($codes[0]);   // shown once

        auth()->logout();
        $this->flushSession();

        return [$secret, $codes];
    }

    public function test_enrolment_stores_the_secret_encrypted_and_shows_recovery_codes_once(): void
    {
        [$secret] = $this->enroll();
        $raw = \DB::table('users')->where('id', $this->owner->id)->first();
        $this->assertNotSame($secret, $raw->two_factor_secret);           // encrypted at rest
        $this->assertStringNotContainsString($secret, (string) $raw->two_factor_recovery_codes);
        $this->assertSame($secret, $this->owner->fresh()->two_factor_secret);
    }

    public function test_login_needs_the_code_and_accepts_it_only_once(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // rate limit has its own test below
        [$secret, $codes] = $this->enroll();
        $login = fn () => $this->post('/login', ['email' => 'owner@example.com', 'password' => 'ChangeMe@123']);

        // password alone does not sign in
        $login()->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Enter your sign-in code');

        // wrong code
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        // right code
        $otp = TwoFactor::engine()->getCurrentOtp($secret);
        $this->post('/two-factor-challenge', ['code' => $otp])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->owner);

        // the same code cannot be replayed
        auth()->logout();
        $this->flushSession();
        $login();
        $this->post('/two-factor-challenge', ['code' => $otp])->assertSessionHasErrors('code');
        $this->assertGuest();

        // a recovery code works exactly once
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertRedirect('/dashboard');
        auth()->logout();
        $this->flushSession();
        $login();
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge', ['code' => $codes[1]])->assertRedirect('/dashboard');
        $this->assertSame(6, $this->owner->fresh()->recoveryCodesLeft());
    }

    public function test_guessing_codes_is_rate_limited(): void
    {
        $this->enroll();
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'ChangeMe@123']);
        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->post('/two-factor-challenge', ['code' => sprintf('%06d', $i)])->status();
        }
        $this->assertContains(429, $statuses);
        $this->assertSame(302, $statuses[0]);
        $this->assertGuest();
    }

    public function test_challenge_cannot_be_opened_without_a_password_step_and_expires(): void
    {
        $this->get('/two-factor-challenge')->assertNotFound();
        $this->enroll();
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'ChangeMe@123']);
        \Carbon\Carbon::setTestNow(now()->addMinutes(11));
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/login');
        \Carbon\Carbon::setTestNow();
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    public function test_disable_needs_password_and_code_and_regenerating_replaces_old_codes(): void
    {
        [$secret, $codes] = $this->enroll();
        $this->actingAs($this->owner);

        $this->post('/profile/two-factor/recovery-codes', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/profile/two-factor/recovery-codes', ['password' => 'ChangeMe@123'])->assertSessionHasNoErrors();
        $new = session('recovery_codes');
        $this->assertCount(8, $new);
        $this->assertFalse(TwoFactor::useRecoveryCode($this->owner->fresh(), $codes[2]));   // old codes dead
        $this->assertTrue(TwoFactor::useRecoveryCode($this->owner->fresh(), $new[0]));

        $this->post('/profile/two-factor/disable', ['password' => 'ChangeMe@123', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/profile/two-factor/disable', ['password' => 'wrong', 'code' => $new[1]])->assertSessionHasErrors('password');
        $this->assertTrue($this->owner->fresh()->hasTwoFactor());
        $this->post('/profile/two-factor/disable', ['password' => 'ChangeMe@123', 'code' => $new[1]])->assertSessionHasNoErrors();
        $this->assertFalse($this->owner->fresh()->hasTwoFactor());
        $this->assertNull($this->owner->fresh()->two_factor_secret);
    }

    public function test_admins_can_be_required_to_use_it_and_an_admin_can_reset_a_locked_out_user(): void
    {
        $admin = User::create(['name' => 'Adm', 'email' => 'adm@x.com', 'password' => 'password123']);
        $admin->assignRole('Admin');
        $worker = User::create(['name' => 'Wk', 'email' => 'wk@x.com', 'password' => 'password123']);
        $worker->assignRole('User');

        // off by default → admins pass
        $this->actingAs($admin)->get('/orders')->assertOk();

        // switch the rule on (owner, via Settings)
        $this->actingAs($this->owner)->put('/settings', ['business_name' => 'Lumiere Premium', 'order_prefix' => 'ORD-', 'invoice_prefix' => 'INV-', 'require_2fa_admins' => 1])->assertSessionHasNoErrors();
        $this->assertSame('1', biz('require_2fa_admins'));

        $this->actingAs($admin)->get('/orders')->assertRedirect('/profile?tab=security');      // forced to set it up
        $this->get('/profile?tab=security')->assertOk();                                       // …where it is reachable
        $this->post('/logout')->assertRedirect();
        $this->actingAs($worker)->get('/orders')->assertOk();                                  // ordinary users are not affected

        // enrol the admin, then the owner resets them (lost phone)
        $this->actingAs($admin)->post('/profile/two-factor/enable');
        $secret = session('two_factor_setup');
        $this->post('/profile/two-factor/confirm', ['code' => TwoFactor::engine()->getCurrentOtp($secret)]);
        $this->assertTrue($admin->fresh()->hasTwoFactor());
        $this->actingAs($admin)->get('/orders')->assertOk();                                   // satisfied the rule

        \App\Models\Setting::put('require_2fa_admins', '0'); // (the owner has not enrolled in this test; the rule would lock them out too)
        $this->actingAs($this->owner)->put("/users/{$admin->id}", ['name' => 'Adm', 'email' => 'adm@x.com', 'role' => 'Admin', 'locale' => 'en', 'is_active' => 1, 'reset_two_factor' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->hasTwoFactor());
    }
}
