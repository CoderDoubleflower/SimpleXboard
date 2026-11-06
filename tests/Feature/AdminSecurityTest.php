<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private string $prefix;
    private string $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::query()->forceCreate([
            'email' => 'admin@example.com', 'password' => Hash::make('old-admin-password'),
            'is_admin' => true, 'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]);
        $this->prefix = '/api/v2/' . hash('crc32b', config('app.key'));
        $this->auth = (new AuthService($this->admin))->generateAuthData()['auth_data'];
    }

    public function test_admin_login_and_authenticated_profile_exclude_subscription_credentials(): void
    {
        $login = $this->postJson('/api/v1/passport/auth/login', ['email' => 'ADMIN@EXAMPLE.COM', 'password' => 'old-admin-password']);
        $login->assertOk()->assertJsonPath('data.is_admin', true);
        $this->assertArrayNotHasKey('token', $login->json('data'));
        $this->withHeader('Authorization', $login->json('data.auth_data'))->getJson($this->prefix . '/auth/me')
            ->assertOk()->assertJsonPath('data.email', 'admin@example.com')->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.uuid');
    }

    public function test_password_change_revokes_every_session_and_redacts_audit(): void
    {
        (new AuthService($this->admin))->generateAuthData();
        $this->withHeader('Authorization', $this->auth)->postJson($this->prefix . '/auth/password', [
            'old_password' => 'incorrect-old-password', 'new_password' => 'new-admin-password', 'new_password_confirmation' => 'new-admin-password',
        ])->assertUnprocessable();
        $this->withHeader('Authorization', $this->auth)->postJson($this->prefix . '/auth/password', [
            'old_password' => 'old-admin-password', 'new_password' => 'new-admin-password', 'new_password_confirmation' => 'new-admin-password',
        ])->assertOk();
        $this->assertTrue(Hash::check('new-admin-password', $this->admin->fresh()->password));
        $this->assertSame(0, $this->admin->tokens()->count());
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $this->auth)->getJson($this->prefix . '/auth/me')->assertForbidden();
        $audit = AdminAuditLog::pluck('request_data')->implode(' ');
        $this->assertStringNotContainsString('old-admin-password', $audit);
        $this->assertStringNotContainsString('new-admin-password', $audit);
    }

    public function test_logout_revokes_current_session_and_revoke_all_invalidates_the_rest(): void
    {
        $other = (new AuthService($this->admin))->generateAuthData()['auth_data'];
        $this->withHeader('Authorization', $this->auth)->getJson($this->prefix . '/auth/sessions')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson($this->prefix . '/auth/logout')->assertOk();
        $this->assertSame(1, $this->admin->tokens()->count());
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $this->auth)->getJson($this->prefix . '/auth/me')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', $other)->postJson($this->prefix . '/auth/revokeSessions')->assertOk();
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_password_change_preserves_leading_and_trailing_spaces(): void
    {
        $oldPassword = ' old-admin-password ';
        $newPassword = ' new-admin-password ';
        $this->admin->forceFill(['password' => Hash::make($oldPassword)])->save();
        $this->withHeader('Authorization', $this->auth)->postJson($this->prefix . '/auth/password', [
            'old_password' => $oldPassword,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ])->assertOk();
        $this->assertTrue(Hash::check($newPassword, $this->admin->fresh()->password));
        $this->postJson('/api/v1/passport/auth/login', [
            'email' => $this->admin->email, 'password' => $newPassword,
        ])->assertOk();
    }

    public function test_banned_admin_and_expired_tokens_cannot_reach_admin_api(): void
    {
        $this->admin->tokens()->update(['expires_at' => now()->subMinute()]);
        $this->withHeader('Authorization', $this->auth)->getJson($this->prefix . '/auth/me')->assertForbidden();
        app('auth')->forgetGuards();
        $this->admin->tokens()->update(['expires_at' => now()->addDay()]);
        $this->admin->banned = true;
        $this->admin->save();
        $this->getJson($this->prefix . '/auth/me')->assertForbidden();
    }

    public function test_active_settings_are_validated_and_mutable_paths_apply_without_restarting_router(): void
    {
        $this->withHeader('Authorization', $this->auth)->postJson($this->prefix . '/config/save', ['frontend_theme' => 'old'])->assertUnprocessable();
        $this->postJson($this->prefix . '/config/save', ['subscribe_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->postJson($this->prefix . '/config/save', ['subscribe_url' => ['https://example.test']])->assertUnprocessable();
        $this->postJson($this->prefix . '/config/save', ['subscribe_url' => 'https://example.test/?0'])->assertUnprocessable();
        $this->postJson($this->prefix . '/config/save', ['subscribe_url' => 'https://0@example.test'])->assertUnprocessable();
        $this->postJson($this->prefix . '/config/save', ['subscribe_path' => 'samepath', 'secure_path' => 'samepath'])->assertUnprocessable();
        $this->postJson($this->prefix . '/config/save', ['secure_path' => 'private-new-panel', 'subscribe_path' => 'private-sub'])
            ->assertOk()->assertJsonPath('data.admin_url', '/private-new-panel');
        $this->getJson($this->prefix . '/auth/me')->assertNotFound();
        $this->getJson('/api/v2/private-new-panel/auth/me')->assertOk();
    }

    public function test_nested_private_keys_are_redacted_from_audit_logs(): void
    {
        $this->withHeader('Authorization', $this->auth)->postJson($this->prefix . '/config/save', [
            'server_token' => 'test-secret-node-token',
        ])->assertOk();
        $log = AdminAuditLog::latest('id')->first();
        $this->assertStringNotContainsString('test-secret-node-token', json_encode($log->request_data));
        $this->assertStringContainsString('redacted', json_encode($log->request_data));
    }

    public function test_cli_password_recovery_only_accepts_admin_and_revokes_sessions(): void
    {
        $this->artisan('reset:password', ['email' => 'admin@example.com', 'password' => 'cli-new-admin-password'])->assertExitCode(0);
        $this->assertTrue(Hash::check('cli-new-admin-password', $this->admin->fresh()->password));
        $this->assertSame(0, $this->admin->tokens()->count());
        $this->artisan('reset:password', ['email' => 'unknown@example.com', 'password' => 'cli-new-admin-password'])->assertExitCode(1);
    }
}
