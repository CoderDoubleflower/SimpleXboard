<?php

namespace Tests\Feature;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivatePanelSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_member_portal_registration_mail_and_commerce_endpoints_are_gone(): void
    {
        foreach (['v1', 'v2'] as $version) {
            foreach (['register', 'forget', 'loginWithMailLink', 'getQuickLoginUrl'] as $action) {
                $this->postJson("/api/{$version}/passport/auth/{$action}")->assertNotFound();
            }
            $this->getJson("/api/{$version}/passport/auth/token2Login")->assertNotFound();
            $this->postJson("/api/{$version}/passport/comm/sendEmailVerify")->assertNotFound();
        }
        foreach (['info', 'getSubscribe', 'getStat', 'checkLogin', 'getActiveSession', 'notice/fetch', 'knowledge/fetch', 'telegram/getBotInfo', 'plan/fetch', 'order/fetch', 'invite/fetch'] as $path) {
            $this->getJson('/api/v1/user/' . $path)->assertNotFound();
        }
        foreach (['order/save', 'ticket/save', 'coupon/check', 'gift-card/redeem', 'transfer', 'changePassword'] as $path) {
            $this->postJson('/api/v1/user/' . $path)->assertNotFound();
        }
        $this->postJson('/api/v1/guest/telegram/webhook')->assertNotFound();
        $this->getJson('/api/v1/guest/comm/config')->assertNotFound();
    }

    public function test_retired_admin_surfaces_have_no_routes_or_settings_compatibility_groups(): void
    {
        Sanctum::actingAs($this->admin());
        $prefix = '/api/v2/' . hash('crc32b', config('app.key'));
        foreach (['plan/fetch', 'server/group/fetch', 'notice/fetch', 'knowledge/fetch', 'theme/getThemes', 'plugin/getPlugins', 'mail/template/list', 'stat/getOverride', 'stat/getOrder', 'order/fetch', 'payment/fetch'] as $path) {
            $this->getJson($prefix . '/' . $path)->assertNotFound();
        }
        $this->postJson($prefix . '/user/generate')->assertNotFound();
        $config = $this->getJson($prefix . '/config/fetch')->assertOk();
        foreach (['invite', 'app', 'email', 'telegram', 'frontend'] as $key) {
            $config->assertJsonMissingPath('data.' . $key);
        }
        $this->getJson($prefix . '/stat/getStats')->assertOk()->assertJsonMissingPath('data.todayIncome');
    }

    public function test_backend_requires_admin_and_unknown_admin_path_does_not_expose_api(): void
    {
        $prefix = '/api/v2/' . hash('crc32b', config('app.key'));
        $this->getJson($prefix . '/user/fetch')->assertForbidden();
        $this->getJson('/api/v2/not-the-admin-path/user/fetch')->assertNotFound();
        $this->postJson($prefix . '/backup/create')->assertForbidden();
        $this->get('/')->assertOk()->assertSeeText('SimpleXboard');
        $this->get('/not-the-admin-path')->assertNotFound();
    }

    private function admin(): User
    {
        return User::query()->forceCreate([
            'email' => 'admin@example.com', 'password' => bcrypt('private-admin-password'),
            'is_admin' => true, 'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]);
    }
}
