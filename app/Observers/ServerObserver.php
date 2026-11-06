<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;

class ServerObserver
{
    public function created(Server $server): void
    {
        $machineId = $server->machine_id;
        DB::afterCommit(function () use ($machineId) {
            if ($machineId) NodeSyncService::notifyMachineNodesChanged($machineId);
        });
    }

    public function updated(Server $server): void
    {
        // Capture originals before Eloquent replaces them; deliver only after commit.
        $nodeId = $server->id;
        $oldLimit = $server->getOriginal('transfer_enable');
        $oldUsed = (int) $server->getOriginal('u') + (int) $server->getOriginal('d');
        $wasAvailable = (bool) $server->getOriginal('enabled')
            && ($oldLimit === null || (int) $oldLimit === 0 || $oldUsed < $oldLimit);
        $fullSync = $server->wasChanged(['enabled', 'transfer_enable']) || $wasAvailable !== $server->isAvailable();
        $configChanged = $server->wasChanged([
            'server_port', 'protocol_settings', 'type', 'route_ids',
            'custom_outbounds', 'custom_routes', 'cert_config',
        ]);
        $machineIds = $server->wasChanged(['machine_id', 'enabled'])
            ? array_unique(array_filter([$server->machine_id, $server->getOriginal('machine_id')])) : [];
        DB::afterCommit(function () use ($nodeId, $fullSync, $configChanged, $machineIds) {
            if ($fullSync) NodeSyncService::notifyFullSync($nodeId);
            elseif ($configChanged) NodeSyncService::notifyConfigUpdated($nodeId);
            foreach ($machineIds as $machineId) NodeSyncService::notifyMachineNodesChanged($machineId);
        });
    }

    public function deleted(Server $server): void
    {
        $nodeId = $server->id;
        $machineId = $server->machine_id;
        DB::afterCommit(function () use ($nodeId, $machineId) {
            if (NodeSyncService::isNodeOnline($nodeId)) {
                NodeSyncService::push($nodeId, 'sync.users', ['users' => []]);
            }
            if ($machineId) NodeSyncService::notifyMachineNodesChanged($machineId);
        });
    }
}
