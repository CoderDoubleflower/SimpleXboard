<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 10;
    private bool $revokeExisting = false;

    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        bool $revokeExisting = false
    ) {
        $this->revokeExisting = $revokeExisting;
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        // Read current committed state; delayed jobs must never resurrect old credentials.
        $user = User::find($this->userId);
        if ($user) {
            NodeSyncService::notifyUserChanged($user, $this->revokeExisting);
        } else {
            NodeSyncService::notifyUserRemoved($this->userId);
        }
    }
}
