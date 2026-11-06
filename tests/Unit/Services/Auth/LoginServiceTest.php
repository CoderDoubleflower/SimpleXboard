<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
    }

    public function test_members_cannot_sign_in_even_with_an_old_valid_password(): void
    {
        $member = $this->identity(false);
        [$success] = app(LoginService::class)->login($member->email, 'a-long-test-password');
        $this->assertFalse($success);
        $this->assertNull($member->fresh()->last_login_at);
        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_admin_login_normalizes_email_and_clears_failed_attempts(): void
    {
        $admin = $this->identity(true);
        [$failed] = app(LoginService::class)->login('ADMIN@EXAMPLE.COM', 'bad-password');
        $this->assertFalse($failed);
        [$success, $found] = app(LoginService::class)->login('ADMIN@EXAMPLE.COM', 'a-long-test-password');
        $this->assertTrue($success);
        $this->assertSame($admin->id, $found->id);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_failed_login_is_rate_limited_and_banned_admin_is_rejected(): void
    {
        admin_setting(['password_limit_count' => 2]);
        $admin = $this->identity(true);
        $service = app(LoginService::class);
        $service->login($admin->email, 'bad');
        $service->login($admin->email, 'bad');
        [$success, $error] = $service->login($admin->email, 'a-long-test-password');
        $this->assertFalse($success);
        $this->assertSame(429, $error[0]);
        Cache::flush();
        $admin->banned = true;
        $admin->save();
        $this->assertFalse($service->login($admin->email, 'a-long-test-password')[0]);
    }

    private function identity(bool $admin): User
    {
        return User::query()->forceCreate([
            'email' => 'admin@example.com', 'password' => Hash::make('a-long-test-password'),
            'is_admin' => $admin, 'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]);
    }
}
