<?php

namespace App\Services;

use App\Jobs\NodeUserSyncJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function isAvailable(User $user): bool
    {
        return $user->isAvailable();
    }

    public function getAvailableUsers()
    {
        return User::eligible()->get();
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function getAllUsers()
    {
        return User::where('is_admin', false)->get();
    }

    public function trafficFetch(Server $server, string $protocol, array $data): void
    {
        // Callers may hold an old node model. Freeze the database rate at
        // report receipt, not when a queue worker eventually handles the job.
        $current = Server::whereKey($server->getKey())->first(['rate']);
        if (!$current) return;
        $snapshot = $server->toArray();
        $snapshot['rate'] = $current->rate;
        $rateHundredths = $current->getRateHundredths();
        $timestamp = now()->startOfDay()->timestamp;
        collect($data)->chunk(1000)->each(function ($chunk) use ($timestamp, $snapshot, $protocol, $rateHundredths) {
            TrafficFetchJob::dispatch($snapshot, $chunk->toArray(), $protocol, $timestamp, $rateHundredths);
            StatUserJob::dispatch($snapshot, $chunk->toArray(), $protocol, 'd');
            StatServerJob::dispatch($snapshot, $chunk->toArray(), $protocol, 'd');
        });
    }

    public function getUserTrafficInfo(User $user): array
    {
        return [
            'upload' => $user->getActualUpload(),
            'download' => $user->getActualDownload(),
            'legacy_upload' => $user->legacy_u,
            'legacy_download' => $user->legacy_d,
            'total_used' => $user->getTotalUsedTraffic(),
            'total_available' => $user->transfer_enable,
            'remaining' => $user->getRemainingTraffic(),
            'usage_percentage' => $user->getTrafficUsagePercentage(),
            'last_reset_at' => $user->last_reset_at,
            'reset_count' => $user->reset_count,
        ];
    }

    /** Prepare a subscription-only member. The caller saves it in a transaction. */
    public function createUser(array $data): User
    {
        if (blank($data['name'] ?? null) && blank($data['email'] ?? null)) {
            throw ValidationException::withMessages(['name' => '请填写成员名称或邮箱']);
        }
        if (array_key_exists('transfer_enable', $data) && $data['transfer_enable'] !== null && $data['transfer_enable'] < 0) {
            throw ValidationException::withMessages(['transfer_enable' => '流量上限不能小于零']);
        }
        $user = new User();
        foreach (['name', 'email', 'expired_at', 'transfer_enable', 'speed_limit', 'device_limit', 'remarks', 'access_mode'] as $field) {
            if (array_key_exists($field, $data)) $user->{$field} = $data[$field];
        }
        $user->password = null;
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        return $user;
    }

    public function syncServerAccess(User $user, array $serverIds): void
    {
        $changes = $user->servers()->sync($user->access_mode === 'restricted' ? array_values(array_unique($serverIds)) : []);
        if ($changes['attached'] || $changes['detached'] || $changes['updated']) {
            NodeUserSyncJob::dispatch($user->id, 'updated')->afterCommit();
        }
    }
}
