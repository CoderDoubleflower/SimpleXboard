<?php


namespace App\Jobs;

use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $data;
    protected array $server;
    protected string $protocol;
    protected string $recordType;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    public function handle(): void
    {
        $recordAt = $this->recordType === 'm'
            ? strtotime(date('Y-m-01'))
            : strtotime(date('Y-m-d'));

        foreach ($this->data as $uid => $v) {
            try {
                $this->processUserStat($uid, $v, $recordAt);
            } catch (\Exception $e) {
                Log::error('StatUserJob failed for user ' . $uid . ': ' . $e->getMessage());
                throw $e;
            }
        }
    }

    protected function processUserStat(int $uid, array $v, int $recordAt): void
    {
        $driver = config('database.default');
        if ($driver === 'sqlite') {
            $this->processUserStatForSqlite($uid, $v, $recordAt);
        } elseif ($driver === 'pgsql') {
            $this->processUserStatForPostgres($uid, $v, $recordAt);
        } else {
            $this->processUserStatForOtherDatabases($uid, $v, $recordAt);
        }
    }

    protected function processUserStatForSqlite(int $uid, array $v, int $recordAt): void
    {
        DB::transaction(function () use ($uid, $v, $recordAt) {
            $existingRecord = StatUser::where([
                'user_id' => $uid,
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
            ])->first();

            if ($existingRecord) {
                $existingRecord->update([
                    'u' => $existingRecord->u + max(0, (int) $v[0]),
                    'd' => $existingRecord->d + max(0, (int) $v[1]),
                    'updated_at' => time(),
                ]);
            } else {
                StatUser::create([
                    'user_id' => $uid,
                    'record_at' => $recordAt,
                    'record_type' => $this->recordType,
                    'u' => max(0, (int) $v[0]),
                    'd' => max(0, (int) $v[1]),
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
        }, 3);
    }

    protected function processUserStatForOtherDatabases(int $uid, array $v, int $recordAt): void
    {
        StatUser::upsert(
            [
                'user_id' => $uid,
                'record_at' => $recordAt,
                'record_type' => $this->recordType,
                'u' => max(0, (int) $v[0]),
                'd' => max(0, (int) $v[1]),
                'created_at' => time(),
                'updated_at' => time(),
            ],
            ['user_id', 'record_at', 'record_type'],
            [
                'u' => DB::raw("u + VALUES(u)"),
                'd' => DB::raw("d + VALUES(d)"),
                'updated_at' => time(),
            ]
        );
    }

    /**
     * PostgreSQL upsert with arithmetic increments using ON CONFLICT ... DO UPDATE
     */
    protected function processUserStatForPostgres(int $uid, array $v, int $recordAt): void
    {
        $table = (new StatUser())->getTable();
        $now = time();
        $u = max(0, (int) $v[0]);
        $d = max(0, (int) $v[1]);

        $sql = "INSERT INTO {$table} (user_id, record_at, record_type, u, d, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (user_id, record_at, record_type)
                DO UPDATE SET
                    u = {$table}.u + EXCLUDED.u,
                    d = {$table}.d + EXCLUDED.d,
                    updated_at = EXCLUDED.updated_at";

        DB::statement($sql, [
            $uid,
            $recordAt,
            $this->recordType,
            $u,
            $d,
            $now,
            $now,
        ]);
    }
}
