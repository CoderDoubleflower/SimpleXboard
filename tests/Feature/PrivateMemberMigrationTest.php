<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Rehearses a real SQLite file backup, Round 1 upgrade and one-step rollback. */
class PrivateMemberMigrationTest extends TestCase
{
    private string $database;
    private array $previousSqlite;
    private string $migrationPath;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->database = tempnam(sys_get_temp_dir(), 'simplexboard-round2-');
        $this->previousSqlite = config('database.connections.sqlite');
        DB::purge('sqlite');
        config()->set('database.connections.sqlite.database', $this->database);
        DB::setDefaultConnection('sqlite');
        $migrator = app('migrator');
        $migrator->setConnection('sqlite');
        $migrator->getRepository()->createRepository();
        $files = $migrator->getMigrationFiles([database_path('migrations')]);
        $this->migrationPath = $files['2026_11_06_000002_remove_member_plan_dependencies'];
        unset($files['2026_11_06_000002_remove_member_plan_dependencies']);
        $migrator->run(array_values($files), ['step' => true]);
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

    public function test_backup_upgrade_and_rollback_preserve_credentials_grants_quota_history_and_admin_login(): void
    {
        $legacy = $this->seedLegacyState();
        DB::disconnect('sqlite');
        $this->assertTrue(copy($this->database, $this->database . '.backup'));
        $backupHash = hash_file('sha256', $this->database . '.backup');
        $backup = new \PDO('sqlite:' . $this->database . '.backup');
        $this->assertSame('ok', $backup->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(5, (int) $backup->query('SELECT COUNT(*) FROM v2_server')->fetchColumn());
        $backup = null;

        app('migrator')->run([$this->migrationPath], ['step' => true]);
        $member = User::findOrFail($legacy['member']);
        $admin = User::findOrFail($legacy['admin']);
        $this->assertSame($legacy['token'], $member->token);
        $this->assertSame($legacy['uuid'], $member->uuid);
        $this->assertFalse($member->banned);
        $this->assertSame(1024, $member->transfer_enable);
        $this->assertSame(150, $member->getTotalUsedTraffic());
        $this->assertSame(100, $member->legacy_u);
        $this->assertSame(50, $member->legacy_d);
        $this->assertSame(0, $member->getActualUpload());
        $this->assertSame('restricted', $member->access_mode);
        $this->assertNull($member->password);
        $this->assertSame($legacy['admin_password'], $admin->password);
        $this->assertTrue(password_verify('administrator-password', $admin->password));
        $this->assertSame(0, User::findOrFail($legacy['null_quota'])->transfer_enable);
        $this->assertSame(0, User::findOrFail($legacy['zero_quota'])->transfer_enable);
        $this->assertFalse(User::findOrFail($legacy['null_quota'])->isAvailable());
        $this->assertSame(0, User::findOrFail($legacy['expired'])->expired_at);
        $this->assertFalse(User::findOrFail($legacy['expired'])->isAvailable());
        $this->assertEqualsCanonicalizing($legacy['granted_nodes'], $member->servers()->pluck('v2_server.id')->all());
        $this->assertSame([$legacy['visible'], $legacy['standalone_null']], array_column(ServerService::getAvailableServers($member), 'id'));
        $this->assertTrue(Server::findOrFail($legacy['standalone_null'])->enabled);
        $this->assertFalse(Server::findOrFail($legacy['machine_null'])->enabled);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $admin->id)->count());
        $this->assertFalse(Schema::hasTable('v2_plan'));
        $this->assertFalse(Schema::hasColumn('v2_server', 'rate'));
        $this->assertFalse(Schema::hasColumn('v2_user', 'group_id'));
        $this->assertFalse(Schema::hasColumn('v2_user', 'is_staff'));
        $this->assertFalse(Schema::hasColumn('v2_stat_user', 'server_rate'));
        $this->assertSame(0, DB::table('v2_stat_user')->count());
        $this->assertSame(100, (int) DB::table('v2_round2_archive_stat_user')->value('u'));
        $this->assertSame(150, (int) DB::table('v2_stat')->value('transfer_used_total'));
        $this->assertFalse(Schema::hasColumn('v2_stat', 'paid_total'));
        $new = app(UserService::class)->createUser(['name' => 'New shared member']);
        $new->save();
        $this->assertNull($new->transfer_enable);
        $this->assertSame('all', $new->access_mode);
        $this->assertTrue($new->isAvailable());
        $this->assertSame($backupHash, hash_file('sha256', $this->database . '.backup'));

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $old = DB::table('v2_user')->where('id', $member->id)->first();
        $this->assertSame($legacy['token'], $old->token);
        $this->assertSame($legacy['uuid'], $old->uuid);
        $this->assertSame($legacy['plan'], $old->plan_id);
        $this->assertSame($legacy['group'], $old->group_id);
        $this->assertSame(1, (int) $old->is_staff);
        $this->assertSame($legacy['member_password'], $old->password);
        $this->assertSame(1024, (int) $old->transfer_enable);
        $this->assertNull(DB::table('v2_user')->where('id', $legacy['null_quota'])->value('transfer_enable'));
        $this->assertTrue(Schema::hasTable('v2_plan'));
        $this->assertSame(2.0, (float) DB::table('v2_server')->where('id', $legacy['visible'])->value('rate'));
        $this->assertNull(DB::table('v2_server')->where('id', $legacy['standalone_null'])->value('enabled'));
        $this->assertSame(100, (int) DB::table('v2_stat_user')->value('u'));
        $this->assertSame(1, (int) DB::table('v2_user')->where('id', $new->id)->value('banned'));
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', $new->id)->value('transfer_enable'));
        $this->assertTrue(Schema::hasTable('v2_round2_rollback_user'));
        // Rollback keeps revoked sessions revoked; their original bytes remain archived.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count());
        $this->assertSame(2, DB::table('v2_round2_archive_tokens')->count());
    }

    public function test_upgrading_after_optional_round_one_cleanup_does_not_require_commercial_columns(): void
    {
        $legacy = $this->seedLegacyState();
        $cleanup = require database_path('cleanup/2026_11_06_000001_remove_retired_commerce.php');
        $cleanup->up();
        app('migrator')->run([$this->migrationPath], ['step' => true]);
        $this->assertSame($legacy['uuid'], User::findOrFail($legacy['member'])->uuid);
        $this->assertFalse(Schema::hasTable('v2_order'));
        $this->assertFalse(Schema::hasTable('v2_round2_archive_order'));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasColumn('v2_user', 'balance'));
        $this->assertFalse(Schema::hasColumn('v2_plan', 'prices'));
        $this->assertSame($legacy['group'], DB::table('v2_user')->where('id', $legacy['member'])->value('group_id'));
    }

    private function seedLegacyState(): array
    {
        // Some upgraded installations allowed null quotas; they were never unlimited.
        Schema::table('v2_user', fn (Blueprint $table) => $table->bigInteger('transfer_enable')->nullable()->default(0)->change());
        $now = time();
        $group = DB::table('v2_server_group')->insertGetId(['name' => 'Existing private group', 'created_at' => $now, 'updated_at' => $now]);
        $otherGroup = DB::table('v2_server_group')->insertGetId(['name' => 'Other group', 'created_at' => $now, 'updated_at' => $now]);
        $plan = DB::table('v2_plan')->insertGetId([
            'name' => 'Old internal config', 'group_id' => $group, 'transfer_enable' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $machine = DB::table('v2_server_machine')->insertGetId(['name' => 'Existing machine', 'token' => Helper::guid(), 'created_at' => now(), 'updated_at' => now()]);
        $node = fn (array $extra) => DB::table('v2_server')->insertGetId($extra + [
            'name' => 'Legacy node', 'type' => 'vless', 'host' => 'node.example.test', 'port' => '443', 'server_port' => 443,
            'show' => true, 'enabled' => true, 'rate' => 2, 'group_ids' => json_encode([(string) $group]),
            'protocol_settings' => json_encode(['tls' => 2, 'network' => 'tcp']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $visible = $node([]);
        $hidden = $node(['show' => false, 'group_ids' => json_encode([$group])]);
        $other = $node(['group_ids' => json_encode([(string) $otherGroup]), 'enabled' => false]);
        $standaloneNull = $node(['enabled' => null]);
        $machineNull = $node(['enabled' => null, 'machine_id' => $machine]);
        $memberPassword = password_hash('legacy-member-password', PASSWORD_DEFAULT);
        $adminPassword = password_hash('administrator-password', PASSWORD_DEFAULT);
        $token = Helper::guid();
        $uuid = Helper::guid(true);
        $user = fn (array $extra) => DB::table('v2_user')->insertGetId($extra + [
            'email' => Helper::randomChar(8) . '@example.test', 'password' => $memberPassword,
            'is_admin' => false, 'is_staff' => true, 'token' => Helper::guid(), 'uuid' => Helper::guid(true),
            'group_id' => $group, 'plan_id' => $plan, 'transfer_enable' => 1024, 'u' => 100, 'd' => 50,
            'expired_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $member = $user(['token' => $token, 'uuid' => $uuid]);
        $admin = $user(['is_admin' => true, 'is_staff' => false, 'password' => $adminPassword]);
        $nullQuota = $user(['transfer_enable' => null]);
        $zeroQuota = $user(['transfer_enable' => 0]);
        $expired = $user(['expired_at' => 0]);
        foreach ([$member, $admin] as $id) DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class, 'tokenable_id' => $id, 'name' => 'legacy session',
            'token' => hash('sha256', Helper::guid()), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('v2_stat_user')->insert([
            'user_id' => $member, 'server_rate' => 2, 'u' => 100, 'd' => 50,
            'record_at' => $now, 'record_type' => 'd', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('v2_stat')->insert([
            'record_at' => $now, 'record_type' => 'd', 'register_count' => 5, 'transfer_used_total' => 150,
            'order_count' => 1, 'order_total' => 100, 'paid_count' => 1, 'paid_total' => 100,
            'commission_count' => 0, 'commission_total' => 0, 'invite_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return [
            'group' => $group, 'plan' => $plan, 'member' => $member, 'admin' => $admin,
            'token' => $token, 'uuid' => $uuid, 'member_password' => $memberPassword, 'admin_password' => $adminPassword,
            'null_quota' => $nullQuota, 'zero_quota' => $zeroQuota, 'expired' => $expired,
            'visible' => $visible, 'standalone_null' => $standaloneNull, 'machine_null' => $machineNull,
            'granted_nodes' => [$visible, $hidden, $standaloneNull, $machineNull],
        ];
    }
}
