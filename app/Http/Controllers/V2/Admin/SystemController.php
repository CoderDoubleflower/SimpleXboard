<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use App\Helpers\ResponseEnum;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        try {
            $data = [
                'schedule' => $this->getScheduleStatus(),
                'horizon' => $this->getHorizonStatus(),
                'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
                'degraded' => false,
            ];
        } catch (\Throwable) {
            $data = ['schedule' => false, 'horizon' => false, 'schedule_last_runtime' => null,
                'degraded' => true, 'message' => '运行状态暂不可用，请检查 Redis 与队列服务'];
        }
        return $this->success($data);
    }

    public function getQueueWorkload(WorkloadRepository $workload)
    {
        return $this->success(collect($workload->get())->sortBy('name')->values()->toArray());
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        }) ? false : true;
    }

    public function getQueueStats()
    {
        $data = [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $this->getHorizonStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
        return $this->success($data);
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));

        $builder = AdminAuditLog::with('admin:id,email')
            ->orderBy('id', 'DESC')
            ->when($request->input('action'), fn($q, $v) => $q->where('action', $v))
            ->when($request->input('admin_id'), fn($q, $v) => $q->where('admin_id', $v))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('uri', 'like', '%' . $keyword . '%')
                      ->orWhere('request_data', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        return response(['data' => $res, 'total' => $total]);
    }

    public function getHorizonFailedJobs(Request $request, JobRepository $jobRepository)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = min(100, max(1, (int) $request->input('page_size', 20)));
        try {
            $failed = collect($jobRepository->getFailed())->sortByDesc('failed_at')
                ->slice(($current - 1) * $pageSize, $pageSize)->values()
                ->map(function ($job) {
                    $payload = json_decode(data_get($job, 'payload', '{}'), true) ?: [];
                    return [
                        'id' => data_get($job, 'id'),
                        'name' => $payload['displayName'] ?? data_get($job, 'name', '队列任务'),
                        'connection' => data_get($job, 'connection'),
                        'queue' => data_get($job, 'queue'),
                        'exception' => mb_substr((string) data_get($job, 'exception', ''), 0, 12000),
                        'failed_at' => data_get($job, 'failed_at'),
                    ];
                });
            return response()->json(['data' => $failed, 'total' => $jobRepository->countFailed(),
                'current' => $current, 'page_size' => $pageSize]);
        } catch (\Throwable) {
            return response()->json(['data' => [], 'total' => 0, 'current' => $current, 'page_size' => $pageSize,
                'degraded' => true, 'message' => '暂时无法读取队列状态，请检查 Redis 与 Horizon']);
        }
    }

}
