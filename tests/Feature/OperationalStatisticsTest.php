<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Services\StatisticalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 12:00:00');
        Bus::fake();
        // Reading persisted operational metrics must not open a traffic Redis connection.
        Redis::shouldReceive('connection')->never();
        foreach (['v2_order', 'v2_payment', 'v2_commission_log', 'v2_invite_code', 'v2_ticket', 'v2_ticket_message'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_rollup_uses_the_record_day_without_commerce_or_redis(): void
    {
        $yesterday = Carbon::yesterday()->timestamp;
        $today = Carbon::today()->timestamp;
        $this->member(['created_at' => $yesterday]);
        $this->member(['created_at' => $today]);
        $this->member(['is_admin' => true, 'created_at' => $yesterday]);
        // Yesterday's persisted traffic can be written today by the queue consumer.
        $this->serverStat(1, $yesterday, 120, 80);
        $this->serverStat(1, $today, 30, 70);
        $this->serverStat(1, $yesterday + 60, 9000, 9000, 'm');

        $this->artisan('xboard:statistics')->assertSuccessful();
        $this->artisan('xboard:statistics')->assertSuccessful();

        $this->assertSame(1, Stat::count());
        $stat = Stat::firstOrFail();
        $this->assertEquals($yesterday, $stat->record_at);
        $this->assertSame('d', $stat->record_type);
        $this->assertEquals(1, $stat->register_count);
        $this->assertEquals(200, $stat->transfer_used_total);
        foreach (['order_count', 'order_total', 'paid_count', 'paid_total', 'commission_count', 'commission_total', 'invite_count'] as $field) {
            $this->assertArrayNotHasKey($field, $stat->getAttributes());
            $this->assertFalse(Schema::hasColumn('v2_stat', $field));
        }
    }

    public function test_server_rank_sums_only_daily_records_inside_the_selected_period(): void
    {
        $first = $this->server('first');
        $second = $this->server('second');
        $yesterday = Carbon::yesterday()->timestamp;
        $this->serverStat($first->id, $yesterday, 40, 60);
        $this->serverStat($first->id, $yesterday - 86400, 9000, 9000);
        $this->serverStat($first->id, $yesterday + 60, 20000, 20000, 'm');
        $this->serverStat($first->id, Carbon::today()->timestamp, 30000, 30000);
        $this->serverStat($second->id, $yesterday, 100, 200);

        $ranking = StatisticalService::getServerRank('yesterday');

        $this->assertSame([$second->id, $first->id], array_column($ranking, 'server_id'));
        $this->assertSame([300, 100], array_column($ranking, 'total'));
        $this->assertSame(40, $ranking[1]['u']);
        $this->assertSame(60, $ranking[1]['d']);
    }

    public function test_member_traffic_rank_counts_actual_daily_bytes_without_monthly_double_counting(): void
    {
        $memberId = $this->member();
        $today = Carbon::today()->timestamp;
        foreach ([[60, 40, $today, 'd'], [9999, 9999, $today, 'm'], [8888, 8888, Carbon::tomorrow()->timestamp, 'd']] as [$u, $d, $at, $type]) {
            StatUser::create(['user_id' => $memberId, 'u' => $u, 'd' => $d, 'record_at' => $at, 'record_type' => $type]);
        }

        $ranking = app(StatisticalService::class)->getRanking('user_traffic_rank');

        $this->assertCount(1, $ranking);
        $this->assertEquals(100, $ranking[0]['total']);
        $this->assertEquals(60, $ranking[0]['u']);
        $this->assertEquals(40, $ranking[0]['d']);
        $this->assertSame($memberId, $ranking[0]['user_id']);
        $this->assertFalse(Schema::hasColumn('v2_stat_user', 'server_rate'));
    }

    public function test_dashboard_uses_daily_traffic_and_excludes_admins_and_retired_fields(): void
    {
        $this->member(['expired_at' => null, 't' => Carbon::now()->timestamp, 'online_count' => 2]);
        $this->member(['banned' => true, 'created_at' => Carbon::now()->subMonth()->timestamp]);
        $this->member(['is_admin' => true, 't' => Carbon::now()->timestamp, 'online_count' => 99]);
        $this->serverStat(1, Carbon::today()->timestamp, 10, 20);
        $this->serverStat(1, Carbon::yesterday()->timestamp, 30, 40);
        $this->serverStat(1, Carbon::now()->startOfMonth()->timestamp, 1000, 2000, 'm');

        $stats = app(StatController::class)->getStats()->getData(true)['data'];

        $this->assertSame(2, $stats['totalUsers']);
        $this->assertSame(1, $stats['activeUsers']);
        $this->assertSame(1, $stats['currentMonthNewUsers']);
        $this->assertSame(1, $stats['onlineUsers']);
        $this->assertEquals(2, $stats['onlineDevices']);
        $this->assertSame(['upload' => 10, 'download' => 20, 'total' => 30], $stats['todayTraffic']);
        $this->assertSame(['upload' => 40, 'download' => 60, 'total' => 100], $stats['monthTraffic']);
        $this->assertSame($stats['monthTraffic'], $stats['totalTraffic']);
        foreach (['todayIncome', 'currentMonthIncome', 'lastMonthIncome', 'currentMonthCommissionPayout', 'commissionPendingTotal', 'ticketPendingTotal'] as $field) {
            $this->assertArrayNotHasKey($field, $stats);
        }
        $this->assertSame('actual_bytes', $stats['trafficAccounting']['basis']);
    }

    public function test_operational_history_does_not_expose_archived_revenue(): void
    {
        $at = Carbon::yesterday()->timestamp;
        $service = app(StatisticalService::class);
        Stat::create($service->generateStatData() + ['record_at' => $at, 'record_type' => 'd']);
        Stat::where('record_at', $at)->update(['register_count' => 3]);

        $history = $service->getStatRecord('register_count')->toArray();

        $this->assertSame([['record_at' => $at, 'record_type' => 'd', 'register_count' => 3]], $history);
        $this->expectException(\InvalidArgumentException::class);
        $service->getStatRecord('paid_total');
    }

    private function member(array $attributes = []): int
    {
        return DB::table('v2_user')->insertGetId($attributes + [
            'email' => Str::random(12) . '@example.test',
            'password' => null,
            'transfer_enable' => null,
            'token' => Str::random(32),
            'uuid' => (string) Str::uuid(),
            'created_at' => Carbon::now()->timestamp,
            'updated_at' => Carbon::now()->timestamp,
        ]);
    }

    private function server(string $name): Server
    {
        return Server::create([
            'name' => $name,
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'enabled' => true,
        ]);
    }

    private function serverStat(int $serverId, int $at, int $upload, int $download, string $type = 'd'): void
    {
        StatServer::create([
            'server_id' => $serverId,
            'server_type' => Server::TYPE_VMESS,
            'u' => $upload,
            'd' => $download,
            'record_type' => $type,
            'record_at' => $at,
        ]);
    }
}
