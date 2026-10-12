<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_home_and_can_open_the_login_screen(): void
    {
        $this->get('/dashboard')
            ->assertRedirect(route('home'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('lang="ru"', false)
            ->assertSee('С возвращением')
            ->assertDontSee('Забыли пароль?')
            ->assertSee('images/nexus-logo.png');
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Alex Morgan',
            'email' => 'alex@example.com',
            'password' => 'Strong-password-2026',
            'password_confirmation' => 'Strong-password-2026',
            'role' => UserRole::Admin->value,
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'alex@example.com']);
    }

    public function test_user_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'remember' => '1',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee('Рады видеть');

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_session_idle_timeout_invalidates_auth_even_when_requests_keep_the_laravel_session_alive(): void
    {
        config([
            'session.driver' => 'array',
            'session.lifetime' => 15,
            'session.idle_timeout' => 15,
        ]);
        $this->app['session']->forgetDrivers();

        $user = User::factory()->create([
            'email' => 'idle@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $start = Carbon::now();
        try {
            Carbon::setTestNow($start);
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'correct-horse-battery',
            ])->assertRedirect('/dashboard');

            $lastActivity = $start->timestamp;
            $this->get('/dashboard')
                ->assertOk()
                ->assertSeeInOrder(['name="session-idle-timeout-seconds"', 'content="900"'], false)
                ->assertSee('name="session-expire-url"', false)
                ->assertSessionHas('nexus.last_user_activity_at', $lastActivity);

            Carbon::setTestNow($start->copy()->addMinutes(14));
            $this->get('/dashboard')
                ->assertOk()
                ->assertSessionHas('nexus.last_user_activity_at', $lastActivity);

            Carbon::setTestNow($start->copy()->addMinutes(15));
            $this->get('/dashboard')->assertRedirect(route('home'));
            $this->assertGuest();
            $this->get('/')->assertRedirect(route('login'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_real_activity_endpoint_renews_idle_deadline_but_reaches_expiry_at_fifteen_minutes(): void
    {
        config([
            'session.driver' => 'array',
            'session.lifetime' => 15,
            'session.idle_timeout' => 15,
        ]);
        $this->app['session']->forgetDrivers();

        $user = User::factory()->create([
            'email' => 'active@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $start = Carbon::now();
        try {
            Carbon::setTestNow($start);
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'correct-horse-battery',
            ])->assertRedirect('/dashboard');
            $this->get('/dashboard')->assertOk();

            $activityAt = $start->copy()->addMinutes(14);
            Carbon::setTestNow($activityAt);
            $this->post(route('session.activity.update'))
                ->assertNoContent()
                ->assertSessionHas('nexus.last_user_activity_at', $activityAt->timestamp);

            Carbon::setTestNow($activityAt->copy()->addMinutes(14)->addSeconds(59));
            $this->get('/dashboard')->assertOk();

            Carbon::setTestNow($activityAt->copy()->addMinutes(15));
            $this->get('/dashboard')->assertRedirect(route('home'));
            $this->assertGuest();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_logout_endpoint_invalidates_the_session_and_redirects_to_home(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->withSession(['nexus.last_user_activity_at' => now()->timestamp])
            ->post(route('session.expire'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_guests_cannot_update_activity_or_expire_a_session(): void
    {
        $this->post(route('session.activity.update'))->assertRedirect(route('home'));
        $this->post(route('session.expire'))->assertRedirect(route('home'));
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        User::factory()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'member@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_password_reset_request_sends_a_reset_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_recovery_screens_render(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        $token = Password::createToken($user);

        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Сброс пароля');

        $this->get(route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]))->assertOk()
            ->assertSee('Новый пароль');
    }

    public function test_signed_in_user_can_render_password_confirmation_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/user/confirm-password')
            ->assertOk()
            ->assertSee('Подтвердите пароль');
    }

    public function test_user_can_reset_a_password_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-strong-password-2026',
            'password_confirmation' => 'New-strong-password-2026',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('New-strong-password-2026', $user->fresh()->password));
    }
}
