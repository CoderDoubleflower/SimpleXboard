<?php

namespace App\Console\Commands;

use App\Models\Stat;
use App\Services\StatisticalService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class XboardStatistics extends Command
{
    protected $signature = 'xboard:statistics';
    protected $description = '汇总每日成员和流量统计';

    public function handle(StatisticalService $statistics): int
    {
        $startedAt = microtime(true);
        $endAt = Carbon::today()->timestamp;
        $startAt = Carbon::yesterday()->timestamp;
        $statistics->setStartAt($startAt);
        $statistics->setEndAt($endAt);

        try {
            Stat::updateOrCreate(
                ['record_at' => $startAt, 'record_type' => 'd'],
                $statistics->generateStatData()
            );
        } catch (\Throwable $exception) {
            Log::error('每日统计失败', ['exception' => $exception]);
            $this->error('每日统计失败，请检查日志。');
            return self::FAILURE;
        }

        $this->info(sprintf('成员和流量统计已完成，耗时 %.3f 秒。', microtime(true) - $startedAt));
        return self::SUCCESS;
    }
}
