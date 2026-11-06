<?php
namespace App\Services;

use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class StatisticalService
{
    protected $userStats;
    protected $startAt;
    protected $endAt;
    protected $serverStats;
    protected $statServerKey;
    protected $statUserKey;
    protected $redis;

    public function __construct()
    {
        ini_set('memory_limit', -1);
    }

    private function redis()
    {
        return $this->redis ??= Redis::connection();
    }

    public function setStartAt($timestamp)
    {
        $this->startAt = $timestamp;
        $this->statServerKey = "stat_server_{$this->startAt}";
        $this->statUserKey = "stat_user_actual_{$this->startAt}";
    }

    public function setEndAt($timestamp)
    {
        $this->endAt = $timestamp;
    }

    /**
     * Aggregate member creation and traffic without accessing retired commerce tables.
     */
    public function generateStatData(): array
    {
        $startAt = $this->startAt ?? Carbon::today()->timestamp;
        $endAt = $this->endAt ?? Carbon::tomorrow()->timestamp;

        return [
            'register_count' => User::where('is_admin', false)->where('created_at', '>=', $startAt)
                ->where('created_at', '<', $endAt)
                ->count(),
            'transfer_used_total' => StatServer::where('record_at', '>=', $startAt)
                ->where('record_at', '<', $endAt)
                ->where('record_type', 'd')
                ->selectRaw('SUM(u + d) as total')
                ->value('total') ?? 0,
        ];
    }

    /**
     * 往服务器报表缓存正追加流量使用数据
     */
    public function statServer($serverId, $serverType, $u, $d)
    {
        $u_menber = "{$serverType}_{$serverId}_u"; //储存上传流量的集合成员
        $d_menber = "{$serverType}_{$serverId}_d"; //储存下载流量的集合成员
        $this->redis()->zincrby($this->statServerKey, $u, $u_menber);
        $this->redis()->zincrby($this->statServerKey, $d, $d_menber);
    }

    /**
     * 追加用户使用流量
     */
    public function statUser($userId, $u, $d)
    {
        $this->redis()->zincrby($this->statUserKey, $u, "{$userId}_u");
        $this->redis()->zincrby($this->statUserKey, $d, "{$userId}_d");
    }

    /**
     * 获取指定用户的流量使用情况
     */
    public function getStatUserByUserID(int|string $userId): array
    {
        return array_values(array_filter($this->getStatUser(), fn ($row) => $row['user_id'] === (int) $userId));
    }

    /**
     * 获取缓存中的用户报表
     */
    public function getStatUser(): array
    {
        $stats = [];
        foreach ($this->redis()->zrange($this->statUserKey, 0, -1, true) as $member => $value) {
            if (!preg_match('/^(\d+)_(u|d)$/', $member, $match)) {
                continue;
            }
            $id = (int) $match[1];
            $stats[$id] ??= ['record_at' => $this->startAt, 'u' => 0, 'd' => 0, 'user_id' => $id];
            $stats[$id][$match[2]] += (int) $value;
        }
        return array_values($stats);
    }


    /**
     * Retrieve server statistics from Redis cache.
     *
     * @return array<int, array{server_id: int, server_type: string, u: float, d: float}>
     */
    public function getStatServer(): array
    {
        /** @var array<string, array{server_id: int, server_type: string, u: float, d: float}> $stats */
        $stats = [];
        $statsServer = $this->redis()->zrange($this->statServerKey, 0, -1, true);

        foreach ($statsServer as $member => $value) {
            $parts = explode('_', $member);
            if (count($parts) !== 3) {
                continue; // Skip malformed members
            }
            [$serverType, $serverId, $type] = $parts;

            if (!in_array($type, ['u', 'd'], true)) {
                continue; // Skip invalid types
            }

            $key = "{$serverType}_{$serverId}";
            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'server_id' => (int) $serverId,
                    'server_type' => $serverType,
                    'u' => 0.0,
                    'd' => 0.0,
                ];
            }
            $stats[$key][$type] += (float) $value;
        }

        return array_values($stats);
    }

    /**
     * 清除用户报表缓存数据
     */
    public function clearStatUser()
    {
        $this->redis()->del($this->statUserKey);
    }

    /**
     * 清除服务器报表缓存数据
     */
    public function clearStatServer()
    {
        $this->redis()->del($this->statServerKey);
    }

    public function getStatRecord($type)
    {
        if (!in_array($type, ['register_count', 'transfer_used_total'], true)) {
            throw new \InvalidArgumentException('Unsupported operational statistic.');
        }

        return Stat::select(['record_at', 'record_type', $type])
            ->where('record_type', 'd')
            ->where('record_at', '>=', $this->startAt ?? Carbon::now()->startOfMonth()->timestamp)
            ->where('record_at', '<', $this->endAt ?? Carbon::tomorrow()->timestamp)
            ->orderBy('record_at', 'ASC')
            ->get();
    }

    public function getRanking($type, $limit = 20)
    {
        return match ($type) {
            'server_traffic_rank' => $this->buildServerTrafficRank($limit),
            'user_traffic_rank' => $this->buildUserTrafficRank($limit),
            default => throw new \InvalidArgumentException('Unsupported traffic ranking.'),
        };
    }

    /**
     * 获取指定日期范围内的节点流量排行
     * @param mixed ...$times 可选值：'today', 'yesterday', 'last_week'，或指定日期范围，格式：timestamp
     * @return array
     */

    public static function getServerRank(...$times)
    {
        $startAt = Carbon::today()->timestamp;
        $endAt = Carbon::tomorrow()->timestamp;

        if (count($times) == 1) {
            switch ($times[0]) {
                case 'today':
                    $startAt = Carbon::today()->startOfDay()->timestamp;
                    $endAt = Carbon::tomorrow()->timestamp;
                    break;
                case 'yesterday':
                    $startAt = Carbon::yesterday()->startOfDay()->timestamp;
                    $endAt = Carbon::today()->timestamp;
                    break;
                case 'last_week':
                    $startAt = Carbon::now()->subWeek()->startOfWeek()->timestamp;
                    $endAt = Carbon::tomorrow()->timestamp;
                    break;
            }
        } else if (count($times) == 2) {
            $startAt = $times[0];
            $endAt = $times[1];
        }

        $period = function ($query) use ($startAt, $endAt) {
            $query->where('record_at', '>=', $startAt)
                ->where('record_at', '<', $endAt)
                ->where('record_type', 'd');
        };

        $statistics = Server::whereHas('stats', $period)
            ->with('parent')
            ->withSum(['stats as u' => $period], 'u')
            ->withSum(['stats as d' => $period], 'd')
            ->get()
            ->map(function ($item) {
                return [
                    'server_name' => optional($item->parent)->name ?? $item->name,
                    'server_id' => $item->id,
                    'server_type' => $item->type,
                    'u' => (int) $item->u,
                    'd' => (int) $item->d,
                    'total' => (int) $item->u + (int) $item->d,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->toArray();
        return $statistics;
    }

    private function buildUserTrafficRank($limit)
    {
        $stats = StatUser::select([
            'user_id',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt ?? Carbon::today()->timestamp)
            ->where('record_at', '<', $this->endAt ?? Carbon::tomorrow()->timestamp)
            ->where('record_type', 'd')
            ->groupBy('user_id')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
        $users = User::whereIn('id', $stats->pluck('user_id')->toArray())->get()->keyBy('id');
        foreach ($stats as $k => $v) {
            if (!isset($users[$v['user_id']]))
                continue;
            $stats[$k]['email'] = $users[$v['user_id']]['email'];
        }
        return $stats;
    }

    private function buildServerTrafficRank($limit)
    {
        return StatServer::select([
            'server_id',
            'server_type',
            DB::raw('sum(u) as u'),
            DB::raw('sum(d) as d'),
            DB::raw('sum(u) + sum(d) as total')
        ])
            ->where('record_at', '>=', $this->startAt ?? Carbon::today()->timestamp)
            ->where('record_at', '<', $this->endAt ?? Carbon::tomorrow()->timestamp)
            ->where('record_type', 'd')
            ->groupBy('server_id', 'server_type')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get();
    }
}
