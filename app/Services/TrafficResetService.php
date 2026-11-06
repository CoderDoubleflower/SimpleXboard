<?php

namespace App\Services;

use App\Models\TrafficResetLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Hard usage limits reset only through an explicit administrator action. */
class TrafficResetService
{
    public function canReset(User $user): bool
    {
        return $user->exists && !$user->is_admin;
    }

    public function manualReset(User $user, array $metadata = []): bool
    {
        if (!$this->canReset($user)) return false;
        return DB::transaction(function () use ($user, $metadata) {
            $member = User::lockForUpdate()->findOrFail($user->id);
            $oldUpload = (int) $member->u;
            $oldDownload = (int) $member->d;
            $legacyUpload = (int) $member->legacy_u;
            $legacyDownload = (int) $member->legacy_d;
            $member->update([
                'u' => 0, 'd' => 0, 'legacy_u' => 0, 'legacy_d' => 0,
                'last_reset_at' => time(), 'reset_count' => (int) $member->reset_count + 1,
            ]);
            TrafficResetLog::create([
                'user_id' => $member->id,
                'reset_type' => TrafficResetLog::TYPE_MANUAL,
                'reset_time' => now(),
                'old_upload' => $oldUpload,
                'old_download' => $oldDownload,
                'old_total' => $oldUpload + $oldDownload,
                'new_upload' => 0, 'new_download' => 0, 'new_total' => 0,
                'trigger_source' => TrafficResetLog::SOURCE_MANUAL,
                'metadata' => $metadata + ['legacy_upload' => $legacyUpload, 'legacy_download' => $legacyDownload],
            ]);
            foreach (["user_traffic_{$member->id}", "user_reset_status_{$member->id}", "user_subscription_{$member->token}"] as $key) {
                Cache::forget($key);
            }
            $user->refresh();
            return true;
        });
    }

    public function getUserResetHistory(User $user, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $user->trafficResetLogs()->orderByDesc('reset_time')->limit($limit)->get();
    }
}
