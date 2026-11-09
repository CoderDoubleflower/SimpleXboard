<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Compatibility is advertised by authenticated agents, independently of health. */
class OutboundCapabilityService
{
    public const HEADER = 'X-SimpleXboard-Features';
    public const FEATURE = 'managed-outbound-v1';

    public static function advertised(Request $request): bool
    {
        return in_array(self::FEATURE, preg_split('/[\s,]+/', $request->header(self::HEADER, ''), -1, PREG_SPLIT_NO_EMPTY), true);
    }

    public static function remember(?Server $node, ?ServerMachine $machine, bool $supported): void
    {
        // Do not expire a capability while a healthy agent uses only WebSocket.
        // Every authenticated HTTP request replaces it, including older agents.
        if ($machine) Cache::forever('outbound-capability:machine:' . $machine->id, $supported);
        if ($node) Cache::forever(self::nodeKey($node), $supported);
    }

    public static function supportsNode(Server $node): bool
    {
        return Cache::get(self::nodeKey($node)) === true
            && (!$node->machine_id || self::supportsMachine((int) $node->machine_id));
    }

    public static function supportsMachine(int $machineId): bool
    {
        return Cache::get('outbound-capability:machine:' . $machineId) === true;
    }

    public static function validateSelection(?Server $current, ?int $outboundId, ?int $machineId): void
    {
        if (!$outboundId) return;
        if ($current && (int) $current->outbound_id === $outboundId
            && (int) $current->machine_id === (int) $machineId) return;
        $supported = $machineId ? self::supportsMachine($machineId)
            : ($current && !$current->machine_id && self::supportsNode($current));
        if (!$supported) {
            throw ValidationException::withMessages(['outbound_id' => $machineId
                ? '请先升级并接入支持出站管理的节点端（sing-box），再选择出站。'
                : '独立节点请先以直连方式创建，接入支持出站管理的节点端（sing-box）后再选择出站。']);
        }
    }

    /** Call only after validating the node/machine credentials. */
    public static function authenticate(Request $request): void
    {
        $node = $request->attributes->get('node_info');
        $machine = $request->attributes->get('machine_info');
        $supported = self::advertised($request);
        self::remember($node, $machine, $supported);
        if (!$node?->outbound_id || $supported) return;

        NodeHealthService::report($node, ['core_status' => [
            'state' => 'error', 'ready' => false, 'users' => 0, 'applied_users' => 0,
            'error' => '节点端不支持出站管理，请升级至 v1.13-simplexboard.2 或兼容版本并使用 sing-box。',
        ]]);
        if (NodeSyncService::isNodeOnline($node->id)) {
            NodeSyncService::push($node->id, 'sync.users', ['users' => []]);
        }
        // Return an empty full user snapshot to withdraw any legacy grant.
        if ($request->is('*/user')) return;
        throw new ApiException('This node requires managed-outbound-v1; upgrade xboard-node and use sing-box.', 409);
    }

    private static function nodeKey(Server $node): string
    {
        // Moving a node to another machine invalidates its old capability.
        return 'outbound-capability:node:' . $node->id . ':machine:' . (int) $node->machine_id;
    }
}
