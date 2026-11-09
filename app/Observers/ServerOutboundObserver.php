<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerOutbound;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;

class ServerOutboundObserver
{
    public function updated(ServerOutbound $outbound): void
    {
        if (!$outbound->wasChanged(['type', 'settings'])) return;

        $outboundId = $outbound->id;
        DB::afterCommit(function () use ($outboundId) {
            // Hidden nodes still serve traffic. Read the references after commit.
            foreach (Server::where('outbound_id', $outboundId)->pluck('id') as $nodeId) {
                NodeSyncService::notifyConfigUpdated($nodeId);
            }
        });
    }
}
