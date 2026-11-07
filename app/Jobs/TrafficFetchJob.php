<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    // Old serialized jobs have no multiplier snapshot and retain their 1x rate.
    protected int $rateHundredths = 100;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp, int $rateHundredths = 100)
    {
        if ($rateHundredths < 0 || $rateHundredths > 10000) {
            throw new \InvalidArgumentException('Traffic multiplier must be between 0 and 100.00');
        }
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->rateHundredths = $rateHundredths;
    }

    public function handle(): void
    {
        $data = $this->data;
        ksort($data, SORT_NUMERIC);
        $userIds = DB::transaction(function () use ($data) {
            if (DB::getDriverName() === 'sqlite') {
                // SQLite ignores FOR UPDATE. Reserve its writer lock before a
                // read snapshot exists so busy_timeout can wait for other writers;
                // a deferred SELECT -> UPDATE upgrade would fail immediately.
                DB::table('v2_user')->whereRaw('0 = 1')->update(['u' => DB::raw('u')]);
            }
            $updated = [];
            foreach ($data as $uid => $traffic) {
                $member = DB::table('v2_user')->where('id', $uid)->lockForUpdate()->first();
                if (!$member) continue;
                $upload = $this->bytes($traffic[0]);
                $download = $this->bytes($traffic[1]);
                [$uploadCharge, $uploadRemainder] = $this->charge($upload, (int) $member->billing_u_remainder);
                [$downloadCharge, $downloadRemainder] = $this->charge($download, (int) $member->billing_d_remainder);
                $quotaUpload = $this->add((int) $member->u, $uploadCharge);
                $quotaDownload = $this->add((int) $member->d, $downloadCharge);
                $actualUpload = $this->add((int) $member->actual_u, $upload);
                $actualDownload = $this->add((int) $member->actual_d, $download);
                // APIs and eligibility also add both directions; keep their sums integral.
                $this->add($quotaUpload, $quotaDownload);
                $this->add($actualUpload, $actualDownload);

                // Query-builder updates deliberately avoid observer/Redis side effects
                // inside the retriable transaction. The quota sweep handles revocation.
                DB::table('v2_user')->where('id', $member->id)->update([
                    'u' => $quotaUpload, 'd' => $quotaDownload,
                    'actual_u' => $actualUpload, 'actual_d' => $actualDownload,
                    'billing_u_remainder' => $uploadRemainder,
                    'billing_d_remainder' => $downloadRemainder,
                    't' => time(), 'updated_at' => time(),
                ]);
                $updated[] = (int) $member->id;
            }
            return $updated;
        }, 3);

        if (!empty($userIds)) {
            try {
                Redis::sadd('traffic:pending_check', ...$userIds);
            } catch (\Throwable $exception) {
                // Billing has committed. Do not fail/retry it because Redis failed;
                // the scheduled full ineligible-member sweep still revokes access.
                Log::warning('Traffic quota check notification failed after billing committed', [
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function bytes(mixed $value): int
    {
        $bytes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Reported traffic must be a non-negative integer within the platform range');
        }
        return $bytes;
    }

    /** Returns whole quota bytes and the remaining hundredths of a byte. */
    private function charge(int $bytes, int $remainder): array
    {
        if ($remainder < 0 || $remainder > 99) {
            throw new \UnexpectedValueException('Stored fractional traffic must be between 0 and 99');
        }
        if ($this->rateHundredths === 0) return [0, $remainder];

        // Split before multiplying so representable values above 2^53 remain exact.
        $whole = intdiv($bytes, 100);
        if ($whole > intdiv(PHP_INT_MAX, $this->rateHundredths)) {
            throw new \OverflowException('Charged traffic exceeds the supported integer range');
        }
        $fraction = ($bytes % 100) * $this->rateHundredths + $remainder;
        $charged = $this->add($whole * $this->rateHundredths, intdiv($fraction, 100));
        return [$charged, $fraction % 100];
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new \OverflowException('Traffic counters exceed the supported integer range');
        }
        return $left + $right;
    }
}
