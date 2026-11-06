<?php

namespace App\Console\Commands;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '同步额度耗尽、到期及停用成员的节点撤销';

    public function handle(): int
    {
        $count = (int) Redis::scard('traffic:pending_check');
        $pending = $count > 0 ? (array) Redis::spop('traffic:pending_check', $count) : [];
        // Expiry needs revocation even when a member has stopped reporting traffic.
        $ineligible = User::whereNotIn('id', User::eligible()->select('id'))->pluck('id')->all();
        foreach (array_unique(array_merge($pending, $ineligible)) as $id) {
            NodeUserSyncJob::dispatch((int) $id, 'updated')->afterCommit();
        }
        return self::SUCCESS;
    }
}
