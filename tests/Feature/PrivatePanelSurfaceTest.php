<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_legacy_settings_cannot_reenable_public_registration(): void
    {
        admin_setting([
            'stop_register' => 0,
            'email_verify' => 1,
            'invite_force' => 1,
            'email_whitelist_enable' => 1,
            'email_whitelist_suffix' => ['example.com'],
            'try_out_plan_id' => 1,
            'try_out_hour' => 24,
        ]);

        $this->getJson('/api/v1/guest/comm/config')->assertOk()
            ->assertJsonPath('data.stop_register', 1)
            ->assertJsonPath('data.is_email_verify', 0)
            ->assertJsonPath('data.is_invite_force', 0)
            ->assertJsonPath('data.email_whitelist_suffix', 0)
            ->assertJsonMissingPath('data.try_out_plan_id')
            ->assertJsonMissingPath('data.try_out_hour');
    }

    public function test_admin_settings_keep_core_configuration_writable_and_ignore_retired_keys(): void
    {
        Sanctum::actingAs($this->member(true));
        $archived = [
            'stop_register' => 0,
            'try_out_plan_id' => 99,
            'try_out_hour' => 24,
            'invite_force' => 1,
            'commission_first_time_enable' => 1,
            'commission_rate' => 15,
            'withdraw_close_enable' => 0,
            'withdraw_methods' => ['legacy'],
            'currency' => 'USD',
            'currency_symbol' => '$',
            'plan_change_enable' => 1,
            'surplus_enable' => 1,
            'new_order_event_id' => 1,
            'renew_order_event_id' => 1,
            'change_order_event_id' => 1,
            'remind_mail_enable' => 1,
            'default_remind_expire' => 1,
            'default_remind_traffic' => 1,
            'windows_version' => 'old',
            'windows_download_url' => 'https://example.com/old.exe',
            'email_whitelist_enable' => 1,
            'email_verify' => 1,
            'register_limit_by_ip_enable' => 1,
        ];
        admin_setting($archived);

        $attempted = array_fill_keys(array_keys($archived), 'retired');
        $this->postJson($this->adminPath('config/save'), $attempted + [
            'app_name' => 'Private members',
            'server_pull_interval' => 60,
            'password_limit_enable' => true,
        ])->assertOk()->assertJsonPath('data', true);

        $this->assertSame('Private members', admin_setting('app_name'));
        $this->assertSame(60, admin_setting('server_pull_interval'));
        $this->assertSame(1, admin_setting('password_limit_enable'));
        foreach ($archived as $key => $value) {
            $this->assertSame($value, admin_setting($key), "Retired setting {$key} must not be written.");
        }

        $this->getJson($this->adminPath('config/fetch'))->assertOk()
            ->assertJsonPath('data.site.app_name', 'Private members')
            ->assertJsonPath('data.site.stop_register', 1)
            ->assertJsonPath('data.invite', [])
            ->assertJsonPath('data.app', [])
            ->assertJsonMissingPath('data.site.try_out_plan_id')
            ->assertJsonMissingPath('data.site.currency')
            ->assertJsonMissingPath('data.subscribe.plan_change_enable')
            ->assertJsonMissingPath('data.subscribe.default_remind_expire')
            ->assertJsonMissingPath('data.email.remind_mail_enable')
            ->assertJsonMissingPath('data.safe.email_whitelist_enable')
            ->assertJsonMissingPath('data.safe.register_limit_by_ip_enable');
    }

    public function test_member_reads_work_without_business_tables_and_hide_archived_values(): void
    {
        $member = $this->member();
        DB::table('v2_user')->where('id', $member->id)->update([
            'balance' => 12345,
            'commission_balance' => 6789,
            'commission_rate' => 25,
            'discount' => 50,
            'remind_expire' => 1,
            'remind_traffic' => 1,
        ]);
        DB::table('v2_plan')->where('id', $member->plan_id)->update([
            'sell' => 1,
            'renew' => 1,
            'prices' => json_encode(['month_price' => 100]),
        ]);
        foreach ([
            'v2_order', 'v2_payment', 'v2_coupon', 'v2_invite_code', 'v2_commission_log',
            'v2_ticket_message', 'v2_ticket', 'v2_gift_card', 'v2_gift_card_log',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Sanctum::actingAs($member->fresh());

        $this->getJson('/api/v1/user/info')->assertOk()
            ->assertJsonPath('data.email', $member->email)
            ->assertJsonPath('data.uuid', $member->uuid)
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.remind_expire', 0)
            ->assertJsonPath('data.remind_traffic', 0)
            ->assertJsonMissingPath('data.commission_balance')
            ->assertJsonMissingPath('data.commission_rate')
            ->assertJsonMissingPath('data.discount');
        $this->getJson('/api/v1/user/getStat')->assertOk()->assertJsonPath('data', [0, 0, 0]);
        $this->getJson('/api/v1/user/comm/config')->assertOk()
            ->assertJsonPath('data.withdraw_methods', [])
            ->assertJsonPath('data.withdraw_close', 1)
            ->assertJsonPath('data.commission_distribution_enable', 0)
            ->assertJsonMissingPath('data.stripe_pk');

        $response = $this->getJson('/api/v1/user/getSubscribe')->assertOk()
            ->assertJsonPath('data.token', $member->token)
            ->assertJsonPath('data.uuid', $member->uuid)
            ->assertJsonPath('data.transfer_enable', $member->transfer_enable)
            ->assertJsonPath('data.plan.id', $member->plan_id)
            ->assertJsonMissingPath('data.plan.prices')
            ->assertJsonMissingPath('data.plan.sell')
            ->assertJsonMissingPath('data.plan.renew')
            ->assertJsonMissingPath('data.plan.is_internal');
        $this->assertNotEmpty($response->json('data.subscribe_url'));
        $this->assertNotNull($response->json('data.reset_day'));
    }

    public function test_retired_entry_points_are_unreachable_even_for_an_admin(): void
    {
        Sanctum::actingAs($this->member(true));
        foreach ([
            ['POST', '/api/v1/passport/auth/register'],
            ['POST', '/api/v2/passport/auth/register'],
            ['POST', '/api/v1/passport/comm/pv'],
            ['POST', '/api/v2/passport/comm/pv'],
            ['GET', '/api/v1/client/app/getConfig'],
            ['GET', '/api/v1/client/app/getVersion'],
            ['GET', '/api/v2/client/app/getConfig'],
            ['GET', '/api/v2/client/app/getVersion'],
            ['GET', '/api/v1/guest/plan/fetch'],
            ['GET', '/api/v1/guest/payment/notify/example/example'],
            ['POST', '/api/v1/guest/payment/notify/example/example'],
            ['POST', '/api/v1/user/update'],
            ['POST', '/api/v1/user/transfer'],
            ['POST', '/api/v1/user/comm/getStripePublicKey'],
            ['POST', '/api/v1/user/order/save'],
            ['POST', '/api/v1/user/order/checkout'],
            ['GET', '/api/v1/user/order/check'],
            ['GET', '/api/v1/user/order/detail'],
            ['GET', '/api/v1/user/order/fetch'],
            ['GET', '/api/v1/user/order/getPaymentMethod'],
            ['POST', '/api/v1/user/order/cancel'],
            ['GET', '/api/v1/user/plan/fetch'],
            ['GET', '/api/v1/user/invite/save'],
            ['GET', '/api/v1/user/invite/fetch'],
            ['GET', '/api/v1/user/invite/details'],
            ['POST', '/api/v1/user/ticket/reply'],
            ['POST', '/api/v1/user/ticket/close'],
            ['POST', '/api/v1/user/ticket/save'],
            ['GET', '/api/v1/user/ticket/fetch'],
            ['POST', '/api/v1/user/ticket/withdraw'],
            ['POST', '/api/v1/user/coupon/check'],
            ['POST', '/api/v1/user/gift-card/check'],
            ['POST', '/api/v1/user/gift-card/redeem'],
            ['GET', '/api/v1/user/gift-card/history'],
            ['GET', '/api/v1/user/gift-card/detail'],
            ['GET', '/api/v1/user/gift-card/types'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertNotFound();
        }

        foreach ([
            ['GET', 'order/fetch'],
            ['POST', 'order/fetch'],
            ['POST', 'order/update'],
            ['POST', 'order/assign'],
            ['POST', 'order/paid'],
            ['POST', 'order/cancel'],
            ['POST', 'order/detail'],
            ['POST', 'user/setInviteUser'],
            ['GET', 'stat/getOrder'],
            ['GET', 'ticket/fetch'],
            ['POST', 'ticket/fetch'],
            ['POST', 'ticket/reply'],
            ['POST', 'ticket/close'],
            ['GET', 'coupon/fetch'],
            ['POST', 'coupon/fetch'],
            ['POST', 'coupon/generate'],
            ['POST', 'coupon/drop'],
            ['POST', 'coupon/show'],
            ['POST', 'coupon/update'],
            ['GET', 'gift-card/templates'],
            ['POST', 'gift-card/templates'],
            ['POST', 'gift-card/create-template'],
            ['POST', 'gift-card/update-template'],
            ['POST', 'gift-card/delete-template'],
            ['POST', 'gift-card/generate-codes'],
            ['GET', 'gift-card/codes'],
            ['POST', 'gift-card/codes'],
            ['POST', 'gift-card/toggle-code'],
            ['GET', 'gift-card/export-codes'],
            ['POST', 'gift-card/update-code'],
            ['POST', 'gift-card/delete-code'],
            ['GET', 'gift-card/usages'],
            ['POST', 'gift-card/usages'],
            ['GET', 'gift-card/statistics'],
            ['POST', 'gift-card/statistics'],
            ['GET', 'gift-card/types'],
            ['GET', 'payment/fetch'],
            ['GET', 'payment/getPaymentMethods'],
            ['POST', 'payment/getPaymentForm'],
            ['POST', 'payment/save'],
            ['POST', 'payment/drop'],
            ['POST', 'payment/show'],
            ['POST', 'payment/sort'],
        ] as [$method, $path]) {
            $this->json($method, $this->adminPath($path))->assertNotFound();
        }
    }

    private function member(bool $admin = false): User
    {
        $user = app(UserService::class)->createUser([
            'email' => $admin ? 'admin@example.com' : 'member@example.com',
            'password' => 'member-password',
        ]);
        $user->is_admin = $admin;
        $user->save();
        return $user->refresh();
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/' . $path;
    }
}
