<?php

namespace App\Services;

use App\Models\Server;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

/** Node readiness is reported independently of machine and control heartbeats. */
class NodeHealthService
{
    public const CONTROL_TTL = 120;

    private const STATES = [
        'waiting_config', 'waiting_users', 'starting', 'running', 'error', 'stopped',
    ];

    public static function report(Server $node, array $data): void
    {
        $core = $data['core_status'] ?? null;
        if (is_array($core)) {
            $state = in_array($core['state'] ?? null, self::STATES, true) ? $core['state'] : 'unknown';
            $users = self::userCount($core['users'] ?? null);
            $appliedUsers = self::userCount($core['applied_users'] ?? null);
            $ready = ($core['ready'] ?? false) === true && $state === 'running'
                && $users !== null && $users > 0 && $appliedUsers === $users;
            $report = [
                'state' => $state,
                'ready' => $ready,
                'users' => $users,
                'applied_users' => $appliedUsers,
                'error' => is_string($core['error'] ?? null) ? mb_substr($core['error'], 0, 2000) : null,
                'source' => 'core_status',
            ];
        } elseif (array_key_exists('kernel_status', $data) && is_bool($data['kernel_status'])) {
            // Old agents already report IsRunning explicitly. Missing is unknown;
            // machine heartbeats, user pulls and traffic are never readiness proof.
            $running = $data['kernel_status'];
            $users = self::userCount($data['total_users'] ?? null);
            $report = [
                'state' => $running ? 'running' : 'stopped',
                'ready' => $running,
                'users' => $users,
                'applied_users' => null,
                'error' => null,
                'source' => 'kernel_status',
            ];
        } else {
            return; // Do not refresh the age of an earlier readiness report.
        }

        $report['updated_at'] = now()->timestamp;
        $report['expires_at'] = $report['updated_at'] + self::reportTtl();
        // A report is authorized for this exact node. Never let a child's
        // parent_id redirect a write into another node or machine's cache.
        Cache::put(self::coreKey($node, $node->id), $report, max(86400, self::reportTtl() * 2));
    }

    public static function coreStatus(Server $node): array
    {
        $errors = app(ServerCertificateService::class)->errorsForHealthSource($node);
        if ($errors !== []) {
            return [
                'state' => 'error', 'ready' => false, 'users' => null,
                'applied_users' => null, 'error' => collect($errors)->flatten()->first(),
                'updated_at' => null, 'source' => 'panel_validation',
            ];
        }
        $report = Cache::get(self::coreKey($node, $node->healthSourceId()));
        if (!is_array($report)) {
            return [
                'state' => 'unknown', 'ready' => false, 'users' => null,
                'applied_users' => null, 'error' => null, 'updated_at' => null,
                'source' => null,
            ];
        }

        if (now()->timestamp >= ($report['expires_at'] ?? 0)) {
            $report['state'] = 'stale';
            $report['ready'] = false;
        }
        unset($report['expires_at']);
        return $report;
    }

    public static function touchControl(int $nodeId): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, self::CONTROL_TTL);
    }

    public static function touchMachineControl(int $machineId): void
    {
        Cache::put("machine_ws_alive:{$machineId}", true, self::CONTROL_TTL);
    }

    public static function controlOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}", false);
    }

    private static function coreKey(Server $node, int $nodeId): string
    {
        return CacheKey::get('SERVER_' . strtoupper($node->type) . '_CORE_STATUS', $nodeId);
    }

    private static function reportTtl(): int
    {
        return max(300, (int) admin_setting('server_push_interval', 60) * 3);
    }

    private static function userCount(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
