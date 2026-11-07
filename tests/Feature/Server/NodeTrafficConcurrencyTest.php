<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficFetchJob;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual root transactions and an independent SQLite writer, without test savepoints. */
class NodeTrafficConcurrencyTest extends TestCase
{
    private string $database;
    private array $previousSqlite;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->database = tempnam(sys_get_temp_dir(), 'simplexboard-billing-');
        $this->previousSqlite = config('database.connections.sqlite');
        DB::purge('sqlite');
        config()->set('database.connections.sqlite.database', $this->database);
        config()->set('database.connections.sqlite.busy_timeout', 2000);
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        config()->set('database.connections.sqlite', $this->previousSqlite);
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        foreach (glob($this->database . '*') as $file) unlink($file);
        parent::tearDown();
    }

    public function test_database_retry_rolls_back_the_first_attempt_before_recomputing_fractional_accounting(): void
    {
        $member = $this->member();
        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (str_starts_with($query->sql, 'update "v2_user"') && str_contains($query->sql, '"actual_u"')) {
                $writes++;
                if ($writes === 1) throw new \PDOException('database is locked');
            }
        });
        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $member->id)->andReturn(1);
        (new TrafficFetchJob([], [$member->id => [1, 3]], 'vless', time(), 50))->handle();
        $this->assertSame(2, $writes);
        $member->refresh();
        $this->assertSame([0, 1, 1, 3], [$member->u, $member->d, $member->actual_u, $member->actual_d]);
        $this->assertSame([50, 50], [$member->billing_u_remainder, $member->billing_d_remainder]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_sqlite_waits_for_an_independent_writer_and_reads_its_committed_fractional_balance(): void
    {
        $member = $this->member();
        $script = <<<'PHP'
$database = new PDO('sqlite:' . $argv[1]);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('PRAGMA busy_timeout = 2000');
$database->exec('BEGIN IMMEDIATE');
$statement = $database->prepare('UPDATE v2_user SET actual_u = 1, billing_u_remainder = 50 WHERE id = ?');
$statement->execute([(int) $argv[2]]);
fwrite(STDOUT, "writer-locked\n");
fflush(STDOUT);
usleep(300000);
$database->exec('COMMIT');
PHP;
        $writer = new Process([PHP_BINARY, '-r', $script, $this->database, (string) $member->id], env: ['PHPRC' => php_ini_loaded_file() ?: false]);
        $writer->setTimeout(10);
        $writer->start();
        try {
            $this->assertTrue($writer->waitUntil(fn ($type, $output) => str_contains($output, 'writer-locked')));
            Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $member->id)->andReturn(1);
            (new TrafficFetchJob([], [$member->id => [1, 0]], 'vless', time(), 50))->handle();
            $this->assertSame(0, $writer->wait(), $writer->getErrorOutput());
            $member->refresh();
            $this->assertSame([1, 0, 2, 0], [$member->u, $member->d, $member->actual_u, $member->actual_d]);
            $this->assertSame([0, 0], [$member->billing_u_remainder, $member->billing_d_remainder]);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $writer->stop();
        }
    }

    private function member(): User
    {
        $member = app(UserService::class)->createUser(['name' => 'Concurrent billing member']);
        $member->save();
        return $member;
    }
}
