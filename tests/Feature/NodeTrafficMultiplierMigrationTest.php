<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NodeTrafficMultiplierMigrationTest extends TestCase
{
    private const MIGRATION = '2026_11_07_000001_add_node_traffic_multipliers';
    private string $database;
    private array $previousSqlite;
    private string $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->database = tempnam(sys_get_temp_dir(), 'simplexboard-multiplier-');
        $this->previousSqlite = config('database.connections.sqlite');
        DB::purge('sqlite');
        config()->set('database.connections.sqlite.database', $this->database);
        DB::setDefaultConnection('sqlite');
        $migrator = app('migrator');
        $migrator->setConnection('sqlite');
        $migrator->getRepository()->createRepository();
        $files = $migrator->getMigrationFiles([database_path('migrations')]);
        $this->migrationPath = $files[self::MIGRATION];
        $migrator->run(array_values(array_filter($files, fn ($name) => $name < self::MIGRATION, ARRAY_FILTER_USE_KEY)), ['step' => true]);
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

    public function test_upgrade_preserves_history_and_credentials_backfills_actual_bytes_and_ignores_archived_rates(): void
    {
        $member = $this->member(['u' => 120, 'd' => 80, 'legacy_u' => 50, 'legacy_d' => 100, 'banned' => true, 'transfer_enable' => 0, 'expired_at' => 0]);
        $large = $this->member(['u' => 9007199254740993, 'legacy_u' => 1, 'transfer_enable' => null]);
        $node = DB::table('v2_server')->insertGetId([
            'name' => 'Existing node', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => '443', 'server_port' => 443, 'enabled' => false, 'show' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $archive = json_encode(['id' => $node, 'rate' => 9, 'rate_time_enable' => true]);
        DB::table('v2_round2_archive_server')->insert(['id' => $node, 'payload' => $archive]);
        $before = (array) DB::table('v2_user')->where('id', $member)->first();

        app('migrator')->run([$this->migrationPath], ['step' => true]);

        $after = (array) DB::table('v2_user')->where('id', $member)->first();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertSame([70, 0], [$after['actual_u'], $after['actual_d']]);
        $this->assertSame([0, 0], [$after['billing_u_remainder'], $after['billing_d_remainder']]);
        $this->assertSame(9007199254740992, User::findOrFail($large)->getActualUpload());
        $this->assertSame('1.00', Server::findOrFail($node)->rate);
        $this->assertSame(100, Server::findOrFail($node)->getRateHundredths());
        $this->assertFalse(Server::findOrFail($node)->enabled);
        $this->assertSame($archive, DB::table('v2_round2_archive_server')->where('id', $node)->value('payload'));
        $this->assertFalse(Schema::hasColumn('v2_server', 'rate_time_enable'));

        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--step' => 1, '--path' => 'database/migrations/' . self::MIGRATION . '.php', '--force' => true,
        ]));
        $this->assertFalse(Schema::hasColumn('v2_user', 'actual_u'));
        $this->assertFalse(Schema::hasColumn('v2_server', 'rate'));
        $this->assertSame($before, (array) DB::table('v2_user')->where('id', $member)->first());
    }

    #[DataProvider('unsafeRollbackStates')]
    public function test_down_refuses_before_any_ddl_if_old_code_cannot_represent_actual_bytes_or_fractional_billing(array $changes): void
    {
        $member = $this->member(['u' => 10, 'd' => 20, 'legacy_u' => 5, 'legacy_d' => 5]);
        app('migrator')->run([$this->migrationPath], ['step' => true]);
        DB::table('v2_user')->where('id', $member)->update($changes);
        $before = (array) DB::table('v2_user')->where('id', $member)->first();
        try {
            (require $this->migrationPath)->down();
            $this->fail('Rollback must not discard actual bytes or fractional charges');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Restore a pre-upgrade backup', $exception->getMessage());
            $this->assertSame($before, (array) DB::table('v2_user')->where('id', $member)->first());
            foreach (['actual_u', 'actual_d', 'billing_u_remainder', 'billing_d_remainder'] as $column) {
                $this->assertTrue(Schema::hasColumn('v2_user', $column));
            }
            $this->assertTrue(Schema::hasColumn('v2_server', 'rate'));
        }
    }

    public static function unsafeRollbackStates(): array
    {
        return [
            'discounted bytes' => [['actual_u' => 100]],
            'weighted quota' => [['actual_d' => 1]],
            'upload carry' => [['billing_u_remainder' => 1]],
            'download carry' => [['billing_d_remainder' => 99]],
        ];
    }

    private function member(array $attributes): int
    {
        return DB::table('v2_user')->insertGetId($attributes + [
            'name' => 'Existing member', 'token' => Helper::guid(), 'uuid' => Helper::guid(true),
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }
}
