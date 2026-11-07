<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Services\StatisticalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StatController extends Controller
{
    public function __construct(private StatisticalService $service)
    {
    }



    public function getServerLastRank()
    {
        return $this->success(data: $this->service->getServerRank('today'));
    }

    public function getServerYesterdayRank()
    {
        return $this->success(data: $this->service->getServerRank('yesterday'));
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);

        $records = StatUser::where('user_id', $request->input('user_id'))
            ->orderBy('record_at', 'DESC')
            ->paginate($request->input('pageSize') ?? 10);

        return ['data' => $records->items(), 'total' => $records->total()];
    }

    public function getStatRecord(Request $request)
    {
        $request->validate([
            'type' => 'required|in:register_count,transfer_used_total',
            'start_at' => 'nullable|integer|min:0',
            'end_at' => 'nullable|integer|min:0',
        ]);
        $this->setPeriod($request, Carbon::now()->startOfMonth()->timestamp);

        return ['data' => $this->service->getStatRecord($request->input('type'))];
    }

    public function getRanking(Request $request)
    {
        $request->validate([
            'type' => 'required|in:server_traffic_rank,user_traffic_rank',
            'start_at' => 'nullable|integer|min:0',
            'end_at' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        $this->setPeriod($request, Carbon::today()->timestamp);

        return ['data' => $this->service->getRanking($request->input('type'), $request->input('limit') ?? 20)];
    }

    private function setPeriod(Request $request, int $defaultStart): void
    {
        $startAt = (int) ($request->input('start_at') ?? $defaultStart);
        $endAt = (int) ($request->input('end_at') ?? Carbon::tomorrow()->timestamp);
        if ($endAt <= $startAt) {
            throw ValidationException::withMessages(['end_at' => '结束时间必须晚于开始时间']);
        }
        $this->service->setStartAt($startAt);
        $this->service->setEndAt($endAt);
    }

    public function getStats()
    {
        return $this->success($this->getOperationalStats());
    }

    private function getOperationalStats(): array
    {
        $now = Carbon::now()->timestamp;
        $currentMonthStart = Carbon::now()->startOfMonth()->timestamp;
        $lastMonthStart = Carbon::now()->startOfMonth()->subMonth()->timestamp;
        $currentMonthNewUsers = User::where('is_admin', false)->where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<=', $now)->count();
        $lastMonthNewUsers = User::where('is_admin', false)->where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)->count();
        $onlineMembers = User::eligible()->where('t', '>=', $now - 600);

        return [
            'currentMonthNewUsers' => $currentMonthNewUsers,
            'totalUsers' => User::where('is_admin', false)->count(),
            'activeUsers' => User::eligible()->count(),
            'totalNodes' => Server::count(),
            'enabledNodes' => Server::where('enabled', true)->count(),
            'userGrowth' => $lastMonthNewUsers > 0
                ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1)
                : 0,
            'onlineUsers' => (clone $onlineMembers)->count(),
            'onlineDevices' => (clone $onlineMembers)->sum('online_count'),
            'onlineNodes' => Server::where('enabled', true)->get()->filter(fn ($server) => (bool) $server->is_online)->count(),
            'todayTraffic' => $this->getTrafficTotals(Carbon::today()->timestamp, Carbon::tomorrow()->timestamp),
            'monthTraffic' => $this->getTrafficTotals($currentMonthStart, Carbon::tomorrow()->timestamp),
            'totalTraffic' => $this->getTrafficTotals(),
            'trafficAccounting' => [
                'basis' => 'actual_bytes',
                'changed_at' => admin_setting('traffic_accounting_changed_at'),
                'description' => '节点与成员流量统计为实际字节；成员额度按各节点倍率累计，旧历史额度单独保留。',
                'legacy_note' => '迁移前的历史额度可能含旧倍率折算量，不计入实际流量。',
            ],
        ];
    }

    private function getTrafficTotals(?int $startAt = null, ?int $endAt = null): array
    {
        $query = StatServer::where('record_type', 'd');
        if ($startAt !== null) {
            $query->where('record_at', '>=', $startAt);
        }
        if ($endAt !== null) {
            $query->where('record_at', '<', $endAt);
        }
        $traffic = $query->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')->first();

        return [
            'upload' => (int) ($traffic->upload ?? 0),
            'download' => (int) ($traffic->download ?? 0),
            'total' => (int) ($traffic->total ?? 0),
        ];
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = (int) ($request->input('start_time') ?? Carbon::now()->subDays(7)->timestamp);
        $endDate = (int) ($request->input('end_time') ?? Carbon::now()->timestamp);
        if ($endDate <= $startDate) {
            throw ValidationException::withMessages(['end_time' => '结束时间必须晚于开始时间']);
        }
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::where('record_type', 'd')->selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<', $endDate)
                ->groupBy('server_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatServer::where('record_type', 'd')->selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');

        } else {
            // Get user traffic data
            $currentData = StatUser::where('record_type', 'd')->selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::where('record_type', 'd')->selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        foreach ($currentData as $data) {
            $previousValue = isset($previousData[$data->id]) ? $previousData[$data->id]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'name' => $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }
}
