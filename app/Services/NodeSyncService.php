<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeSyncService
{
    /**
     * Check if node has active WS connection
     */
    public static function isNodeOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}");
    }

    /**
     * Push node config update
     */
    public static function notifyConfigUpdated(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);
    }

    /**
     * Visit every node so old grants, hidden nodes and removed pivots cannot retain
     * credentials. The small private member set needs no group-based fan-out.
     */
    public static function notifyUserChanged(User $user, bool $revokeExisting = false): void
    {
        foreach (Server::all() as $server) {
            if (!self::isNodeOnline($server->id)) continue;
            $allowed = $user->isAvailable() && $server->isAvailable() && $user->canAccessServer($server);
            if (!$allowed || $revokeExisting) self::removeUserFromNode($server->id, $user->id);
            if ($allowed) {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [[
                        'id' => $user->id, 'uuid' => $user->uuid,
                        'speed_limit' => $user->speed_limit, 'device_limit' => $user->device_limit,
                    ]],
                ]);
            }
        }
    }

    public static function notifyUserRemoved(int $userId): void
    {
        foreach (Server::pluck('id') as $nodeId) {
            if (self::isNodeOnline($nodeId)) self::removeUserFromNode($nodeId, $userId);
        }
    }

    private static function removeUserFromNode(int $nodeId, int $userId): void
    {
        self::push($nodeId, 'sync.user.delta', ['action' => 'remove', 'users' => [['id' => $userId]]]);
    }

    /**
     * Full sync: push config + users to a node
     */
    public static function notifyFullSync(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);

        $users = ServerService::getAvailableUsers($node)->toArray();
        self::push($nodeId, 'sync.users', ['users' => $users]);
    }

    /**
     * Notify machine that its node set has changed.
     * Always publishes via Redis so the WS process can update its in-memory registry.
     */
    public static function notifyMachineNodesChanged(int $machineId): void
    {
        $machine = ServerMachine::find($machineId);

        $nodeList = [];
        if ($machine) {
            $nodes = ServerService::getMachineNodes($machine);
            $nodeList = $nodes->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'name' => $n->name,
            ])->values()->toArray();
        }

        // Always publish via Redis so the WS process can update its in-memory registry
        self::pushMachine($machineId, 'sync.nodes', ['nodes' => $nodeList]);
    }

    /**
     * Publish a push command to Redis — picked up by the Workerman WS server
     */
    public static function push(int $nodeId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'node_id' => $nodeId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis publish failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
                'event' => $event,
            ]);
        }
    }

    /**
     * Publish a machine-level push command to Redis — picked up by the Workerman WS server
     */
    public static function pushMachine(int $machineId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'machine_id' => $machineId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis machine publish failed: {$e->getMessage()}", [
                'machine_id' => $machineId,
                'event' => $event,
            ]);
        }
    }
}
