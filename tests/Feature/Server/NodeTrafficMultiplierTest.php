<?php

namespace Tests\Feature\Server;

use App\Jobs\NodeUserSyncJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NodeTrafficMultiplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('sadd')->byDefault()->andReturn(1);
    }

    public function test_admin_rate_contract_accepts_exact_decimals_and_rejects_dynamic_or_invalid_rates(): void
    {
        $admin = $this->member(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $node = $this->node();
        $payload = $node->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'protocol_settings']);
        $path = '/api/v2/' . admin_setting('secure_path', hash('crc32b', config('app.key'))) . '/server/manage';
        foreach ([0 => '0.00', 1 => '1.00', 100 => '100.00', '.01' => '0.01', '0.29' => '0.29', '1.20' => '1.20'] as $input => $expected) {
            $this->postJson($path . '/save', $payload + ['rate' => $input])->assertOk();
            $this->assertSame($expected, $node->fresh()->rate);
        }
        foreach ([0.01, 0.29, 0.5] as $input) {
            $this->postJson($path . '/save', $payload + ['rate' => $input])->assertOk();
            $this->assertSame((string) $input . ($input === 0.5 ? '0' : ''), $node->fresh()->rate);
        }
        foreach ([-1, '100.01', 101, '0.001', '1.000', '1e2', '1e-2', 'not-a-rate', null, true, []] as $input) {
            $this->postJson($path . '/save', $payload + ['rate' => $input])->assertUnprocessable()->assertJsonValidationErrors('rate');
        }
        foreach ([['rate_time_enable' => true], ['rate_time_enable' => false], ['rate_time_ranges' => []], ['rate_time_ranges' => [['rate' => 2]]]] as $dynamic) {
            $this->postJson($path . '/save', $payload + $dynamic)->assertUnprocessable()->assertJsonValidationErrors(array_keys($dynamic));
        }
        $this->postJson($path . '/save', $payload)->assertOk();
        $this->assertSame('0.50', $node->fresh()->rate); // An omitted update field retains its current value.
        $this->getJson($path . '/getNodes')->assertOk()->assertJsonPath('data.0.rate', '0.50');

        unset($payload['id']);
        $payload['name'] = 'Default rate node';
        $this->postJson($path . '/save', $payload)->assertOk();
        $this->assertSame('1.00', Server::where('name', 'Default rate node')->firstOrFail()->rate);
    }

    public function test_two_nodes_charge_only_member_quota_and_keep_all_statistics_and_node_caps_in_actual_bytes(): void
    {
        $discount = $this->node(['rate' => '0.50', 'transfer_enable' => 304]);
        $double = $this->node(['rate' => '2.00']);
        $member = $this->member(['u' => 70, 'd' => 30, 'legacy_u' => 70, 'legacy_d' => 30]);
        $this->report($discount, $member, 101, 203);
        $this->report($double, $member, 5, 7);

        $member->refresh();
        $this->assertSame([130, 145], [$member->u, $member->d]);
        $this->assertSame([106, 210], [$member->getActualUpload(), $member->getActualDownload()]);
        $this->assertSame([70, 30], [$member->legacy_u, $member->legacy_d]);
        $this->assertSame([50, 50], [$member->billing_u_remainder, $member->billing_d_remainder]);
        $this->assertTrue($member->isAvailable()); // Unlimited members still accumulate quota charges.
        $this->assertDatabaseHas('v2_stat_user', ['user_id' => $member->id, 'u' => 106, 'd' => 210]);
        $this->assertDatabaseHas('v2_stat_server', ['server_id' => $discount->id, 'u' => 101, 'd' => 203]);
        $this->assertDatabaseHas('v2_stat_server', ['server_id' => $double->id, 'u' => 5, 'd' => 7]);
        $this->assertSame([101, 203], [$discount->fresh()->u, $discount->fresh()->d]);
        $this->assertSame([5, 7], [$double->fresh()->u, $double->fresh()->d]);
        $this->assertFalse($discount->fresh()->isAvailable());
        $this->assertCount(0, ServerService::getAvailableUsers($discount->fresh()));
    }

    public function test_ingress_refreshes_a_stale_node_and_serialized_jobs_keep_the_receipt_rate(): void
    {
        $stale = $this->node(['rate' => '0.50']);
        $member = $this->member();
        DB::table('v2_server')->where('id', $stale->id)->update(['rate' => '2.00']);
        app(UserService::class)->trafficFetch($stale, 'vless', [$member->id => [3, 5]]);
        $job = Bus::dispatched(TrafficFetchJob::class)->sole();
        DB::table('v2_server')->where('id', $stale->id)->update(['rate' => '3.00']);
        unserialize(serialize($job))->handle();
        $this->assertSame([6, 10, 3, 5], $this->counters($member));

        $this->report($stale, $member, 3, 5);
        $this->assertSame([15, 25, 6, 10], $this->counters($member));
        $this->assertSame('0.50', $stale->rate);
    }

    public function test_old_serialized_jobs_without_a_multiplier_keep_one_times_accounting(): void
    {
        $node = $this->node(['rate' => '9.00']);
        $member = $this->member();
        $job = new TrafficFetchJob($node->toArray(), [$member->id => [7, 11]], 'vless', time(), 900);
        $payload = $job->__serialize();
        unset($payload["\0*\0rateHundredths"]);
        $old = (new \ReflectionClass(TrafficFetchJob::class))->newInstanceWithoutConstructor();
        $old->__unserialize($payload);
        $old->handle();
        $this->assertSame([7, 11, 7, 11], $this->counters($member));
    }

    #[DataProvider('fractionalReports')]
    public function test_small_reports_preserve_every_fractional_byte(int $hundredths, int $reports, int $upload, int $download, int $expectedUpload, int $expectedDownload): void
    {
        $member = $this->member();
        for ($i = 0; $i < $reports; $i++) {
            (new TrafficFetchJob([], [$member->id => [$upload, $download]], 'vless', time(), $hundredths))->handle();
        }
        $this->assertSame([$expectedUpload, $expectedDownload, $upload * $reports, $download * $reports], $this->counters($member));
        $this->assertSame([0, 0], [$member->billing_u_remainder, $member->billing_d_remainder]);
    }

    public static function fractionalReports(): array
    {
        return [
            'one percent' => [1, 100, 1, 2, 1, 2],
            'twenty-nine percent' => [29, 100, 1, 3, 29, 87],
            'half' => [50, 2, 1, 3, 1, 3],
            'maximum rate' => [10000, 3, 1, 2, 300, 600],
        ];
    }

    public function test_cross_node_rate_changes_and_free_reports_preserve_existing_remainders_and_zero_quota_stays_closed(): void
    {
        $first = $this->node(['rate' => '.50']);
        $second = $this->node(['rate' => '.29']);
        $free = $this->node(['rate' => 0]);
        $member = $this->member(['transfer_enable' => 0]);
        $this->report($first, $member, 1, 1);
        $this->report($free, $member, 11, 5);
        $this->assertSame([0, 0, 12, 6], $this->counters($member));
        $this->assertSame([50, 50], [$member->billing_u_remainder, $member->billing_d_remainder]);
        $this->assertFalse($member->isAvailable());
        $this->assertCount(0, ServerService::getAvailableUsers($free));
        $this->report($second, $member, 1, 1);
        $first->update(['rate' => '.21']);
        $this->report($first, $member, 1, 1);
        $this->assertSame([1, 1, 14, 8], $this->counters($member));
        $this->assertSame([0, 0], [$member->billing_u_remainder, $member->billing_d_remainder]);
    }

    public function test_manual_reset_clears_both_counters_history_and_carry_and_records_original_values(): void
    {
        $member = $this->member([
            'u' => 150, 'd' => 200, 'legacy_u' => 100, 'legacy_d' => 20,
            'actual_u' => 90, 'actual_d' => 180, 'billing_u_remainder' => 50, 'billing_d_remainder' => 79,
        ]);
        $this->assertTrue(app(TrafficResetService::class)->manualReset($member, ['reason' => 'new allowance', 'actual_upload' => 999]));
        $this->assertSame([0, 0, 0, 0], $this->counters($member));
        $this->assertSame([0, 0, 0, 0], [$member->legacy_u, $member->legacy_d, $member->billing_u_remainder, $member->billing_d_remainder]);
        $log = $member->trafficResetLogs()->firstOrFail();
        $this->assertSame(350, $log->old_total);
        $this->assertSame([
            'reason' => 'new allowance', 'actual_upload' => 90, 'legacy_upload' => 100, 'legacy_download' => 20,
            'actual_download' => 180, 'billing_u_remainder' => 50, 'billing_d_remainder' => 79,
        ], $log->metadata);
        (new TrafficFetchJob([], [$member->id => [1, 1]], 'vless', time(), 50))->handle();
        $this->assertSame([0, 0, 1, 1], $this->counters($member));
        $this->assertSame([50, 50], [$member->billing_u_remainder, $member->billing_d_remainder]);
    }

    public function test_bytes_larger_than_double_precision_remain_exact_without_an_overflowing_intermediate_product(): void
    {
        $member = $this->member();
        $bytes = 9007199254740993; // 2^53 + 1 cannot be represented by a PHP double.
        (new TrafficFetchJob([], [$member->id => [$bytes, 0]], 'vless', time(), 100))->handle();
        $this->assertSame([$bytes, 0, $bytes, 0], $this->counters($member));
        $this->assertSame('integer', DB::table('v2_user')->where('id', $member->id)->selectRaw('typeof(actual_u) as storage_type')->value('storage_type'));
        $large = $this->member();
        (new TrafficFetchJob([], [$large->id => [1000000000000000001, 0]], 'vless', time(), 1))->handle();
        $this->assertSame([10000000000000000, 0, 1000000000000000001, 0], $this->counters($large));
        $this->assertSame(1, $large->billing_u_remainder);
    }

    #[DataProvider('overflowCases')]
    public function test_overflow_rolls_back_every_member_in_the_batch(array $attributes, int $reported, int $hundredths): void
    {
        $first = $this->member();
        $second = $this->member($attributes);
        $before = DB::table('v2_user')->orderBy('id')->get()->toJson();
        Redis::shouldReceive('sadd')->never();
        try {
            (new TrafficFetchJob([], [$second->id => [$reported, 0], $first->id => [1, 0]], 'vless', time(), $hundredths))->handle();
            $this->fail('Overflow should stop this batch before any permanent update');
        } catch (\OverflowException) {
            $this->assertSame($before, DB::table('v2_user')->orderBy('id')->get()->toJson());
        }
    }

    public static function overflowCases(): array
    {
        return [
            'multiplied quotient' => [[], PHP_INT_MAX, 10000],
            'multiplied fraction' => [[], intdiv(PHP_INT_MAX, 100) + 1, 10000],
            'quota accumulator' => [['u' => PHP_INT_MAX], 1, 100],
            'actual accumulator at free rate' => [['actual_u' => PHP_INT_MAX], 1, 0],
            'both quota directions' => [['u' => PHP_INT_MAX - 1, 'd' => 1], 1, 100],
            'both actual directions' => [['actual_u' => PHP_INT_MAX - 1, 'actual_d' => 1], 1, 0],
        ];
    }

    public function test_redis_failure_after_commit_does_not_fail_or_repeat_billing_or_dispatch_observers(): void
    {
        $member = $this->member();
        Bus::fake();
        Log::spy();
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('Redis unavailable'));
        (new TrafficFetchJob([], [$member->id => [5, 7]], 'vless', time(), 200))->handle();
        $this->assertSame([10, 14, 5, 7], $this->counters($member));
        Bus::assertNotDispatched(NodeUserSyncJob::class);
        Log::shouldHaveReceived('warning')->once()->with('Traffic quota check notification failed after billing committed', ['exception' => \RuntimeException::class]);
    }

    private function report(Server $node, User $member, int $upload, int $download): void
    {
        Bus::fake();
        app(UserService::class)->trafficFetch($node, 'vless', [$member->id => [$upload, $download]]);
        foreach ([TrafficFetchJob::class, StatUserJob::class, StatServerJob::class] as $class) {
            Bus::dispatched($class)->sole()->handle();
        }
    }

    private function counters(User $member): array
    {
        $member->refresh();
        return [$member->u, $member->d, $member->getActualUpload(), $member->getActualDownload()];
    }

    private function member(array $attributes = []): User
    {
        $member = app(UserService::class)->createUser(['name' => 'Multiplier member']);
        $member->fill($attributes)->save();
        return $member;
    }

    private function node(array $attributes = []): Server
    {
        return Server::create($attributes + [
            'name' => 'Multiplier node ' . Helper::randomChar(5), 'type' => 'vless',
            'host' => 'node.example.test', 'port' => '443', 'server_port' => 443,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp'],
        ]);
    }
}
