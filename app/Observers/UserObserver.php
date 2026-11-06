<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;

class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged(['uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'access_mode', 'is_admin'])) {
            NodeUserSyncJob::dispatch($user->id, 'updated', $user->wasChanged('uuid'))->afterCommit();
        }
    }

    public function created(User $user): void
    {
        NodeUserSyncJob::dispatch($user->id, 'created')->afterCommit();
    }

    public function deleted(User $user): void
    {
        // Delete does not depend on an existing pivot or model being present later.
        NodeUserSyncJob::dispatch($user->id, 'deleted')->afterCommit();
    }
}
