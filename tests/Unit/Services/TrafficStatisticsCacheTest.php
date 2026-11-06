<?php

namespace Tests\Unit\Services;

use App\Services\StatisticalService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class TrafficStatisticsCacheTest extends TestCase
{
    public function test_traffic_cache_writes_reads_and_clears_keep_the_existing_day_keys(): void
    {
        $at = 1793923200;
        $redis = Mockery::mock();
        Redis::shouldReceive('connection')->once()->andReturn($redis);
        $redis->shouldReceive('zincrby')->once()->with("stat_server_{$at}", 10, 'vmess_7_u');
        $redis->shouldReceive('zincrby')->once()->with("stat_server_{$at}", 20, 'vmess_7_d');
        $redis->shouldReceive('zincrby')->once()->with("stat_user_{$at}", 20, '2_9_u');
        $redis->shouldReceive('zincrby')->once()->with("stat_user_{$at}", 40, '2_9_d');
        $redis->shouldReceive('zrange')->once()->with("stat_server_{$at}", 0, -1, true)
            ->andReturn(['vmess_7_u' => 10, 'vmess_7_d' => 20, 'invalid' => 999, 'vmess_7_other' => 999]);
        $redis->shouldReceive('zrange')->once()->with("stat_user_{$at}", 0, -1, true)
            ->andReturn(['2_9_u' => 20, '2_9_d' => 40, '1_11_d' => 900]);
        $redis->shouldReceive('del')->once()->with("stat_user_{$at}");
        $redis->shouldReceive('del')->once()->with("stat_server_{$at}");

        $service = new StatisticalService();
        $service->setStartAt($at);
        $service->statServer(7, 'vmess', 10, 20);
        $service->statUser(2, 9, 20, 40);

        $this->assertSame([[
            'server_id' => 7,
            'server_type' => 'vmess',
            'u' => 10.0,
            'd' => 20.0,
        ]], $service->getStatServer());
        $this->assertSame([[
            'record_at' => $at,
            'server_rate' => '2.00',
            'u' => 20,
            'd' => 40,
            'user_id' => 9,
        ]], $service->getStatUserByUserID(9));
        $service->clearStatUser();
        $service->clearStatServer();
    }
}
